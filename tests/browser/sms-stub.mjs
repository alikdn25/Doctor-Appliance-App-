import { createServer } from 'node:http';

const sent = [];
createServer(async (request, response) => {
    response.setHeader('Content-Type', 'application/json');
    if (request.url === '/health') return response.end('{}');
    if (request.url === '/sent' && request.method === 'GET') return response.end(JSON.stringify(sent));
    if (request.method === 'POST' && request.url === '/2010-04-01/Accounts/ACbrowser/Messages.json') {
        let body = '';
        for await (const chunk of request) body += chunk;
        sent.push(Object.fromEntries(new URLSearchParams(body)));
        response.writeHead(201);
        return response.end(JSON.stringify({ sid: `SMbrowser${sent.length}`, status: 'queued' }));
    }
    response.writeHead(404);
    response.end('{}');
}).listen(9001, '127.0.0.1');
