import 'dotenv/config';
import systemPrompts from './system-prompts.json' with { type: 'json' };
import { FaissStore } from '@langchain/community/vectorstores/faiss';
import { ChatOpenAI, OpenAIEmbeddings } from '@langchain/openai';
import { HumanMessage } from '@langchain/core/messages';
import { createStuffDocumentsChain } from '@langchain/classic/chains/combine_documents';
import { ChatPromptTemplate, MessagesPlaceholder, PromptTemplate, } from '@langchain/core/prompts';

const chatModel = new ChatOpenAI({
  temperature: 0,
  model: 'gpt-4o',
  apiKey: process.env.OPENAI_API_KEY
});

const conversationHistory = new Map();
const vectorStoreCache = new Map();
const HISTORY_TTL_MS = 5 * 60 * 1000;
const RETRIEVAL_CANDIDATES = 30;
const MAX_CONTEXT_DOCUMENTS = 12;
const MAX_HISTORY_MESSAGES = 20;

function getConversationHistory(conversationKey) {
  const entry = conversationHistory.get(conversationKey);
  if (!entry || entry.expiresAt <= Date.now()) {
    conversationHistory.delete(conversationKey);
    return [];
  }
  return entry.messages;
}

function saveConversationHistory(conversationKey, messages) {
  conversationHistory.set(conversationKey, {
    messages: messages.slice(-MAX_HISTORY_MESSAGES),
    expiresAt: Date.now() + HISTORY_TTL_MS,
  });
}

function clearExpiredHistory() {
  const now = Date.now();
  for (const [key, entry] of conversationHistory) {
    if (entry.expiresAt <= now) conversationHistory.delete(key);
  }
}

const historyCleanup = setInterval(clearExpiredHistory, HISTORY_TTL_MS);
historyCleanup.unref?.();

const imagePrompt = PromptTemplate.fromTemplate(`
{answer}

Maak een beeld dat dit uitdrukt, in de stijl van een realistische schildering of cinematische fotografie.
Het beeld moet volledig vrij zijn van tekst, woorden, titels, opschriften, tekstballonnen, ondertitels, letters en logo's.
Geen geschreven elementen in de afbeelding.
`);

const embeddings = new OpenAIEmbeddings({
  apiKey: process.env.OPENAI_API_KEY,
  model: 'text-embedding-3-small',
  dimensions: 256,
  batchSize: 32,
});

const SYSTEM_TEMPLATE = `Answer the user's questions always in Dutch, based on the below context. 
{systemPrompts}

<context>
{context}
</context>
`;

const questionAnsweringPrompt = ChatPromptTemplate.fromMessages([
  ['system', SYSTEM_TEMPLATE],
  new MessagesPlaceholder('messages'),
]);

const documentChain = await createStuffDocumentsChain({
  llm: chatModel,
  prompt: questionAnsweringPrompt,
});

/**
 * @param email
 * @returns {FaissStore}
 */
async function getVectorStore(email) {
  if (!vectorStoreCache.has(email)) {
    const directory = `store/${process.env.AI_PROVIDER}/${email}/v3`;
    const loadingStore = FaissStore.load(directory, embeddings).catch((error) => {
      vectorStoreCache.delete(email);
      throw error;
    });
    vectorStoreCache.set(email, loadingStore);
  }
  return vectorStoreCache.get(email);
}

function deduplicateDocuments(documents) {
  const seenContent = new Set();
  const uniqueDocuments = [];

  for (const document of documents) {
    const content = document.pageContent.trim();
    if (seenContent.has(content)) {
      continue;
    }

    seenContent.add(content);
    uniqueDocuments.push(document);
  }

  return uniqueDocuments;
}

/**
 * Give option to be verbose from the outside
 *
 * @param question
 * @param questionAskedBy
 * @param email
 */
async function askQuestion(question, questionAskedBy, email, conversationKey = questionAskedBy) {
  const isCreative = question.toLowerCase().includes('#creative');
  const personalName = process.env.PERSONAL_NAME;
  const senderName = process.env.SENDER_NAME;
  const personalTag = `#${personalName.toLowerCase()}`;
  const senderTag = `#${senderName.toLowerCase()}`;
  const isPersonalName = question.toLowerCase().includes(personalTag);
  const isSenderName = question.toLowerCase().includes(senderTag);
  const retrievalQuestion = question
    .replace(/#creative/gi, '')
    .replace(new RegExp(personalTag, 'ig'), '')
    .replace(new RegExp(senderTag, 'ig'), '');
  const historyContext = getConversationHistory(conversationKey)
    .slice(-6)
    .map((message) => `${message.role ?? 'user'}: ${message.content}`)
    .join('\n');
  const retrievalQuery = historyContext
    ? `Eerdere conversatie:\n${historyContext}\n\nNieuwe vraag:\n${retrievalQuestion}`
    : retrievalQuestion;

  const vectorStore = await getVectorStore(email);
  const retriever = vectorStore.asRetriever({ k: RETRIEVAL_CANDIDATES });
  const retrievalQueries = historyContext
    ? [retrievalQuestion, retrievalQuery]
    : [retrievalQuestion];
  const retrievedResults = await Promise.all(
    retrievalQueries.map((query) => retriever.invoke(query)),
  );
  const retrievedDocs = retrievedResults.flat();
  const docs = deduplicateDocuments(retrievedDocs).slice(0, MAX_CONTEXT_DOCUMENTS);

  const systemPrompt = isCreative ? JSON.parse(JSON.stringify(systemPrompts.creative)) : JSON.parse(JSON.stringify(systemPrompts.default));
  if (isCreative) {
    question = question.replace(/#creative/gi, '');
  }

  if (isPersonalName || isSenderName) {
    const identity = isPersonalName ? personalName : senderName;
    const otherPerson = isPersonalName ? senderName : personalName;
    systemPrompt.splice(3, 2);
    systemPrompt.shift();
    systemPrompt.push(systemPrompts.identity.replace(/NAME/g, identity).replace(/SENDER/g, questionAskedBy).replace(/OTHER/g, otherPerson));
    question = question.replace(new RegExp(`#${identity.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`, 'ig'), '');
  }
  console.log(systemPrompt);

  const messages = [...getConversationHistory(conversationKey), new HumanMessage(question)];
  const answer = await documentChain.invoke({
    messages,
    context: docs,
    systemPrompts: systemPrompt
  });
  saveConversationHistory(conversationKey, [...messages, { role: 'ai', content: answer }]);
  return answer;
}

/**
 * @param answer
 */
async function generateImage(answer) {
  const prompt = await imagePrompt.format({answer});
  try {
    const response = await fetch('https://api.openai.com/v1/images/generations', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${process.env.OPENAI_API_KEY}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({model: 'gpt-image-1', prompt, n: 1, size: '1536x1024'}),
    });
    if (!response.ok) return null;
    const data = await response.json();
    return data.data?.[0]?.b64_json ?? null;
  } catch (e) {
    return null;
  }
}

async function analyzeImage(image, question, questionAskedBy, email, conversationKey = questionAskedBy) {
  const imageQuestion = question || 'Beschrijf de afbeelding en benoem herkenbare personen, plaatsen, activiteiten en gebeurtenissen.';
  const imageMessage = new HumanMessage({
    content: [
      {type: 'text', text: imageQuestion},
      {type: 'image_url', image_url: {url: `data:${image.mimeType};base64,${image.data}`}},
    ],
  });

  // First extract searchable details from the image so archive retrieval can connect it to historical events.
  const imageDescriptionResponse = await chatModel.invoke([
    {role: 'system', content: 'Beschrijf deze afbeelding feitelijk in het Nederlands. Noem alleen visueel herkenbare details die bruikbaar zijn om een historisch chatarchief te doorzoeken.'},
    imageMessage,
  ]);
  const imageDescription = typeof imageDescriptionResponse.content === 'string'
    ? imageDescriptionResponse.content
    : JSON.stringify(imageDescriptionResponse.content);

  const vectorStore = await getVectorStore(email);
  const retriever = vectorStore.asRetriever({k: 15});
  const docs = await retriever.invoke(`${imageQuestion}\nVisuele beschrijving: ${imageDescription}`);
  const messages = [...getConversationHistory(conversationKey), imageMessage];
  const answer = await documentChain.invoke({
    messages,
    context: docs,
    systemPrompts: [
      ...systemPrompts.default,
      'Verbind je analyse van de afbeelding met relevante gebeurtenissen en gesprekken uit de context.',
      'Maak duidelijk wanneer een verband onzeker is. Verzín geen historische details die niet in de context staan.',
    ],
  });

  saveConversationHistory(conversationKey, [...messages, {role: 'ai', content: answer}]);
  return answer;
}

export { chatModel, embeddings, askQuestion, analyzeImage, generateImage };
