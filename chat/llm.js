import 'dotenv/config';
import systemPrompts from './system-prompts.json' with { type: 'json' };
import { FaissStore } from '@langchain/community/vectorstores/faiss';
import { ChatOpenAI, OpenAIEmbeddings } from '@langchain/openai';
import { ChatGoogleGenerativeAI, GoogleGenerativeAIEmbeddings } from '@langchain/google-genai';
import { HarmBlockThreshold, HarmCategory } from '@google/generative-ai';
import { HumanMessage, AIMessage } from '@langchain/core/messages';
import { createStuffDocumentsChain } from 'langchain/chains/combine_documents';
import {
  ChatPromptTemplate,
  MessagesPlaceholder,
} from '@langchain/core/prompts';

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
 * @param email
 */
async function askQuestion(question, email) {
  const vectorStore = await getVectorStore(email);
  const match = question.match(/\b(2003|2004|2005|2006|2007|2008)\b/);

  const retriever = vectorStore.asRetriever({
    k: 10,
    similarityThreshold: 0.6,
    filter: match ? (doc) => doc.metadata.month.includes(match[0]) : null // NOT WORKING...
  });

  const docs = await retriever.invoke(question);
  return await documentChain.invoke({
    messages: [new HumanMessage(question)],
    context: docs,
    systemPrompts: systemPrompts
  });
}


export { chatModel, embeddings, askQuestion };
