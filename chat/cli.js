import * as readline from 'readline';
import { askQuestion } from './llm.js';

//Initial question and CLI interface to debug input/output
console.log('Stel een vraag');
const rl = readline.createInterface({
  input: process.stdin,
  output: process.stdout,
});

rl.on('line', async (input) => {
  const result = await askQuestion(input, process.env.SENDER_EMAIL);
  console.log(result);
});
