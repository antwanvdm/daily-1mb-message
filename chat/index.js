import express from 'express';
import { askQuestion, generateImage } from './llm.js';

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
  if (typeof req.query.question === 'undefined') {
    res.status(400);
    return res.json({error: 'No parameter question given'});
  }

  const requestsImage = req.query.question.includes('#image');
  const question = req.query.question.replace('#image', '');
  const answer = await askQuestion(question, process.env.SENDER_EMAIL);
  const image = requestsImage ? await generateImage(answer) : null;

  res.json({answer: answer, image: image});
});

app.listen(port, process.env.EXPRESS_HOSTNAME, () => console.log(`Daily 1MB VectorStore listening on port ${port}`));
