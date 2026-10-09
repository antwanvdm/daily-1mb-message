import 'dotenv/config';
import { FaissStore } from '@langchain/community/vectorstores/faiss';
import { Document } from '@langchain/core/documents';
import { embeddings } from './llm.js';
import mysql from 'mysql2/promise';
import fs from 'node:fs/promises';
import path from 'node:path';

const WINDOW_SIZE = 12;
const WINDOW_OVERLAP = 4;
const INDEX_VERSION = 'v2';
const EMBEDDING_BATCH_SIZE = 32;
const MAX_RETRIES = 8;
const CHECKPOINT_INTERVAL = 10;

const connection = await mysql.createConnection({
  host: process.env.DB_HOST,
  user: process.env.DB_USER,
  password: process.env.DB_PASS,
  database: process.env.DB_NAME,
});

try {
  const [accountRows] = await connection.execute('SELECT id, email FROM accounts');
  for (const account of accountRows) {
    if (process.argv[2] !== undefined && account.email !== process.argv[2]) {
      continue;
    }
    await storeVectorForAccount(account.id, account.email);
  }
} finally {
  await connection.end();
}

async function storeVectorForAccount(accountId, email) {
  console.log(email);
  const [messageRows] = await connection.execute(
    `SELECT id, message, messenger, DATE_FORMAT(date, '%Y-%m-%d') AS date,
            TIME_FORMAT(time, '%H:%i:%s') AS time,
            DATE_FORMAT(date, '%Y-%m') AS month,
            YEAR(date) AS year
     FROM messages
     WHERE account_id = ?
     ORDER BY date, time, id`,
    [accountId],
  );
  console.log('selected');

  const messages = messageRows.map((row) => ({
    ...row,
    id: Number(row.id),
    messenger: Number(row.messenger),
    year: Number(row.year),
    dateTime: `${row.date} ${row.time}`,
  }));
  console.log('mapped');

  const documents = [
    ...messages.map((message) => createMessageDocument(accountId, message)),
    ...createConversationDocuments(accountId, messages),
  ];

  if (documents.length === 0) {
    console.log(`Skipping ${email}: no messages found.`);
    return;
  }

  const directory = path.resolve(`store/${process.env.AI_PROVIDER}/${email}/${INDEX_VERSION}`);
  const temporaryDirectory = `${directory}.building`;
  const checkpointPath = path.join(temporaryDirectory, 'checkpoint.json');
  await fs.rm(temporaryDirectory, { recursive: true, force: true });
  await fs.mkdir(temporaryDirectory, { recursive: true });

  let vectorStore;
  for (let start = 0; start < documents.length; start += EMBEDDING_BATCH_SIZE) {
    const batch = documents.slice(start, start + EMBEDDING_BATCH_SIZE);
    vectorStore = vectorStore
      ? await addDocumentsWithRetry(vectorStore, batch)
      : await createStoreWithRetry(batch);

    if ((start / EMBEDDING_BATCH_SIZE + 1) % CHECKPOINT_INTERVAL === 0 || start + batch.length === documents.length) {
      await vectorStore.save(temporaryDirectory);
      await fs.writeFile(checkpointPath, JSON.stringify({ embedded: start + batch.length, total: documents.length }));
      console.log(`Embedded ${start + batch.length}/${documents.length} documents for ${email}.`);
    }
  }

  await fs.rm(checkpointPath, { force: true });
  await fs.rm(directory, { recursive: true, force: true });
  await fs.rename(temporaryDirectory, directory);
  console.log(`Created ${INDEX_VERSION} vector store for ${email}: ${documents.length} documents.`);
}

async function createStoreWithRetry(batch) {
  return withEmbeddingRetry(() => FaissStore.fromDocuments(batch, embeddings));
}

async function addDocumentsWithRetry(vectorStore, batch) {
  await withEmbeddingRetry(() => vectorStore.addDocuments(batch));
  return vectorStore;
}

async function withEmbeddingRetry(operation) {
  for (let attempt = 0; attempt <= MAX_RETRIES; attempt += 1) {
    try {
      return await operation();
    } catch (error) {
      if (!isRateLimitError(error) || attempt === MAX_RETRIES) {
        throw error;
      }
      const delay = Math.min(60_000, 2_000 * 2 ** attempt) + Math.round(Math.random() * 1_000);
      console.warn(`Embedding rate limit reached; retrying in ${Math.ceil(delay / 1000)}s (${attempt + 1}/${MAX_RETRIES}).`);
      await new Promise((resolve) => setTimeout(resolve, delay));
    }
  }
}

function isRateLimitError(error) {
  return error?.status === 429 || error?.code === 'rate_limit_exceeded' || /rate limit|too many requests|429/i.test(error?.message ?? '');
}

function createMessageDocument(accountId, message) {
  return new Document({
    pageContent: formatMessage(message),
    metadata: {
      accountId,
      documentType: 'message',
      messageId: message.id,
      messenger: messengerType(message.messenger),
      date: message.date,
      time: message.time,
      dateTime: message.dateTime,
      month: message.month,
      year: message.year,
    },
  });
}

function createConversationDocuments(accountId, messages) {
  const documents = [];
  for (let start = 0; start < messages.length; start += WINDOW_SIZE - WINDOW_OVERLAP) {
    const window = messages.slice(start, start + WINDOW_SIZE);
    if (window.length === 0) {
      break;
    }
    const first = window[0];
    const last = window[window.length - 1];
    documents.push(new Document({
      pageContent: window.map(formatMessage).join('\n'),
      metadata: {
        accountId,
        documentType: 'conversation',
        messageIds: window.map(({id}) => id),
        startMessageId: first.id,
        endMessageId: last.id,
        startDateTime: first.dateTime,
        endDateTime: last.dateTime,
        month: first.month,
        year: first.year,
        months: [...new Set(window.map(({month}) => month))],
        participants: [...new Set(window.map(({messenger}) => messengerType(messenger)))],
      },
    }));
    if (start + WINDOW_SIZE >= messages.length) {
      break;
    }
  }
  return documents;
}

function formatMessage(message) {
  return `${displayName(message.messenger)} zei op ${message.dateTime}: ${message.message}`;
}

function messengerType(messenger) {
  return messenger === 0 ? 'personal' : messenger === 1 ? 'sender' : 'group';
}

function displayName(messenger) {
  return messenger === 0
    ? process.env.PERSONAL_NAME
    : messenger === 1
      ? process.env.SENDER_NAME
      : 'Iemand in een groepschat';
}
