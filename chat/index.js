import express from 'express';
import { askQuestion, analyzeImage, generateImage } from './llm.js';

const app = express();
const port = process.env.EXPRESS_PORT;
const debug = process.env.DEBUG === 'true';

app.use(express.json());
app.use((req, res, next) => {
  res.header('Access-Control-Allow-Origin', '*');
  res.header('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  res.header('Access-Control-Allow-Headers', 'Access-Control-Allow-Headers, Origin, Accept, X-Requested-With, Content-Type, Access-Control-Request-Methods, Access-Control-Request-Headers, Token');

  if (req.header('Accept') !== 'application/json' && req.method !== 'OPTIONS') {
    res.status(400);
    res.json({error: 'Only JSON is allowed as Accept header, as this webservice only returns JSON'});
    return;
  }

  next();
});

app.get('/ask', async (req, res) => {
  if (typeof req.query.question === 'undefined' || typeof req.query.sender === 'undefined') {
    res.status(400);
    return res.json({error: 'Required parameters are missing'});
  }

  const requestsImage = req.query.question.includes('#image');
  const question = req.query.question.replace('#image', '');
  const sender = parseInt(req.query.sender) === 1 ? process.env.PERSONAL_NAME : process.env.SENDER_NAME;
  const conversationKey = `telegram:private:${process.env.TELEGRAM_PRIVATE_CHAT_ID || 'default'}`;
  const answer = await askQuestion(question, sender, process.env.SENDER_EMAIL, conversationKey);
  const image = requestsImage ? await generateImage(answer) : null;

  res.json({answer: answer, image: image});
});

app.post('/analyze-image', async (req, res) => {
  const {image, question = ''} = req.body;
  if (!image || !image.data || !image.mimeType) {
    return res.status(400).json({error: 'Image data and mimeType are required'});
  }
  const sender = parseInt(req.query.sender ?? req.body.sender) === 1 ? process.env.PERSONAL_NAME : process.env.SENDER_NAME;
  const conversationKey = req.body.conversationKey || `telegram:private:${process.env.TELEGRAM_PRIVATE_CHAT_ID || 'default'}`;
  const answer = await analyzeImage(image, question, sender, process.env.SENDER_EMAIL, conversationKey);
  res.json({answer, image: null});
});

app.listen(port, process.env.EXPRESS_HOSTNAME, () => console.log(`Daily 1MB VectorStore listening on port ${port}`));
