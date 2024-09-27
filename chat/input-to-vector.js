import 'dotenv/config';
import { RecursiveCharacterTextSplitter } from '@langchain/textsplitters';
import { FaissStore } from '@langchain/community/vectorstores/faiss';
import { embeddings } from './llm.js';
import mysql from 'mysql2/promise';

const connection = await mysql.createConnection({
  host: process.env.DB_HOST,
  user: process.env.DB_USER,
  password: process.env.DB_PASS,
  database: process.env.DB_NAME
});

const [userRows] = await connection.execute(
  `SELECT id, email
   FROM accounts;`
);

for (const userRow of userRows) {
  const {id, email} = userRow;
  //If you pass an argument you can also just store 1 course in vector
  if (typeof process.argv[2] !== 'undefined' && email !== process.argv[2]) {
    continue;
  }
  await storeVectorForAccount(id, email);
}

async function storeVectorForAccount(accountId, email) {
  const [messageRows] = await connection.execute(
    `SELECT message, messenger, DATE_FORMAT(date, '%Y-%m') AS month, date, time
     FROM messages
     WHERE account_id = ${accountId}
     ORDER BY month, date;`
  );

  const splitter = new RecursiveCharacterTextSplitter({
    chunkSize: 500,
    chunkOverlap: 50
  });

  let allDocs = [];
  let currentMonth = '';
  let monthMessages = '';

  // Process the rows, group by month
  for (const messageRow of messageRows) {
    const {message, messenger, month, date, time} = messageRow;
    const fullDateTime = `${date.getFullYear()}-${date.getMonth()}-${date.getDate()} ${time}`;

    if (currentMonth && currentMonth !== month) {
      // We've moved to the next month, so process the previous month's messages
      const chunks = await splitter.splitText(monthMessages);
      chunks.forEach(chunk => {
        allDocs.push({
          pageContent: chunk,
          metadata: {month: currentMonth}
        });
      });

      // Reset for the new month
      monthMessages = '';
    }

    // Append the message to the current month's messages
    const whoSaid = messenger === 0 ? process.env.PERSONAL_NAME : (messenger === 1 ? process.env.SENDER_NAME : 'Iemand in een groepschat')
    monthMessages += `${whoSaid} zei op ${fullDateTime}: ${message}` + '\n';
    currentMonth = month;
  }

  // Handle the last month's messages (if any)
  if (monthMessages) {
    const chunks = await splitter.splitText(monthMessages);
    chunks.forEach(chunk => {
      allDocs.push({
        pageContent: chunk,
        metadata: {month: currentMonth}
      });
    });
  }

  // Convert all documents to embeddings and store them in Faiss vector store
  const vectorStore = await FaissStore.fromDocuments(allDocs, embeddings);
  const directory = `store/${process.env.AI_PROVIDER}/${email}`;
  await vectorStore.save(directory);
  console.log('VectorStore successfully created with monthly messages!');
}
