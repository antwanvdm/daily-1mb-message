import 'dotenv/config';
import systemPrompts from './system-prompts.json' with { type: 'json' };
import { FaissStore } from '@langchain/community/vectorstores/faiss';
import { ChatOpenAI, DallEAPIWrapper, OpenAIEmbeddings } from '@langchain/openai';
import { ChatGoogleGenerativeAI, GoogleGenerativeAIEmbeddings } from '@langchain/google-genai';
import { HarmBlockThreshold, HarmCategory } from '@google/generative-ai';
import { HumanMessage } from '@langchain/core/messages';
import { createStuffDocumentsChain } from 'langchain/chains/combine_documents';
import { ChatPromptTemplate, MessagesPlaceholder, PromptTemplate, } from '@langchain/core/prompts';

const chatModel = process.env.AI_PROVIDER === 'openai' ?
  new ChatOpenAI({
    temperature: 0,
    model: 'gpt-4o',
    apiKey: process.env.OPENAI_API_KEY
  }) :
  new ChatGoogleGenerativeAI({
    temperature: 0.8,
    model: 'gemini-1.5-flash-latest',
    apiKey: process.env.GOOGLE_AI_API_KEY,
    safetySettings: [
      {
        category: HarmCategory.HARM_CATEGORY_HARASSMENT,
        threshold: HarmBlockThreshold.BLOCK_NONE,
      }, {
        category: HarmCategory.HARM_CATEGORY_DANGEROUS_CONTENT,
        threshold: HarmBlockThreshold.BLOCK_NONE,
      }, {
        category: HarmCategory.HARM_CATEGORY_HATE_SPEECH,
        threshold: HarmBlockThreshold.BLOCK_NONE,
      }, {
        category: HarmCategory.HARM_CATEGORY_SEXUALLY_EXPLICIT,
        threshold: HarmBlockThreshold.BLOCK_NONE,
      }
    ]
  });

const dallEAPIWrapper = new DallEAPIWrapper({
  n: 1,
  modelName: 'dall-e-3',
  openAIApiKey: process.env.OPENAI_API_KEY,
  size: '1792x1024',
  dallEResponseFormat: 'b64_json'
});

const imagePrompt = PromptTemplate.fromTemplate(`
{answer}

Maak een beeld dat dit uitdrukt, in de stijl van een realistische schildering of cinematische fotografie.
Het beeld moet volledig vrij zijn van tekst, woorden, titels, opschriften, tekstballonnen, ondertitels, letters en logo's.
Geen geschreven elementen in de afbeelding.
`);

const embeddings = process.env.AI_PROVIDER === 'openai' ?
  new OpenAIEmbeddings({
    apiKey: process.env.OPENAI_API_KEY
  }) :
  new GoogleGenerativeAIEmbeddings({
    apiKey: process.env.GOOGLE_AI_API_KEY,
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
  const directory = `store/${process.env.AI_PROVIDER}/${email}`;
  return await FaissStore.load(directory, embeddings);
}

/**
 * Give option to be verbose from the outside
 *
 * @param question
 * @param questionAskedBy
 * @param email
 */
async function askQuestion(question, questionAskedBy, email) {
  const vectorStore = await getVectorStore(email);
  const match = question.match(/\b(2003|2004|2005|2006|2007|2008)\b/);

  const retriever = vectorStore.asRetriever({
    k: 15,
    similarityThreshold: 0.6,
    filter: match ? (doc) => doc.metadata.month.includes(match[0]) : null // NOT WORKING...
  });

  const docs = await retriever.invoke(question);

  const isCreative = question.includes('#creative');
  const systemPrompt = isCreative ? JSON.parse(JSON.stringify(systemPrompts.creative)) : JSON.parse(JSON.stringify(systemPrompts.default));
  if (isCreative) {
    question = question.replace('#creative', '');
  }

  const personalName = process.env.PERSONAL_NAME;
  const senderName = process.env.SENDER_NAME;
  const isPersonalName = question.includes(`#${personalName.toLowerCase()}`);
  const isSenderName = question.includes(`#${senderName.toLowerCase()}`);

  if (isPersonalName || isSenderName) {
    const identity = isPersonalName ? personalName : senderName;
    const otherPerson = isPersonalName ? senderName : personalName;
    systemPrompt.splice(3, 2);
    systemPrompt.shift();
    systemPrompt.push(systemPrompts.identity.replace(/NAME/g, identity).replace(/SENDER/g, questionAskedBy).replace(/OTHER/g, otherPerson));
    question = question.replace(`#${identity.toLowerCase()}`, '');
  }
  console.log(systemPrompt);

  return await documentChain.invoke({
    messages: [new HumanMessage(question)],
    context: docs,
    systemPrompts: systemPrompt
  });
}

/**
 * @param answer
 */
async function generateImage(answer) {
  const dallEPrompt = await imagePrompt.format({answer});
  try {
    return await dallEAPIWrapper.invoke(dallEPrompt);
  } catch (e) {
    return null;
  }
}

export { chatModel, embeddings, askQuestion, generateImage };
