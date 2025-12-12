import * as readline from 'readline';
import { askQuestion, generateImage } from './llm.js';

//Initial question and CLI interface to debug input/output
console.log('Stel een vraag');
const rl = readline.createInterface({
  input: process.stdin,
  output: process.stdout,
});

rl.on('line', async (input) => {
  const requestsImage = input.includes('#image');
  const question = input.replace('#image', '');
  const answer = await askQuestion(question, process.env.SENDER_EMAIL);
  const image = requestsImage ? await generateImage(answer) : null;
  console.log(answer);
});
