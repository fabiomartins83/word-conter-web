// server.js
const express = require('express');
const sqlite3 = require('sqlite3').verbose();
const bodyParser = require('body-parser');

const app = express();
app.use(bodyParser.urlencoded({ extended: false }));
app.use(express.json());

// Constantes de locução
const FASTSPEECH = 950;
const MEDIUMSPEECH = 920;
const SLOWSPEECH = 890;

// Banco SQLite
const path = require('path');
const dbPath = path.join(__dirname, 'word-counter-web-db.sqlite');
const db = new sqlite3.Database(dbPath);

db.serialize(() => {
  db.run(`CREATE TABLE IF NOT EXISTS stats (
    id INTEGER PRIMARY KEY,
    text TEXT
  )`);
});

function formatTime(minutesFloat) {
  const totalSeconds = Math.ceil(minutesFloat * 60);

  if (totalSeconds < 60) return `${totalSeconds}s`;

  const h = Math.floor(totalSeconds / 3600);
  const m = Math.floor((totalSeconds % 3600) / 60);
  const s = totalSeconds % 60;

  return `${h > 0 ? h + 'h ' : ''}${String(m).padStart(2,'0')}min ${String(s).padStart(2,'0')}s`;
}

function analyze(text, mode) {
  // Captura palavras preservando forma original
  const originalWords = text.match(/\p{L}+/gu) || [];

  const wordCount = originalWords.length;
  const charCount = text.length;
  const charNoSpace = text.replace(/\s/g, '').length;
  const sentences = text.split(/[.!?]+/).filter(s => s.trim().length > 0).length;
  const paragraphs = text.split(/\n+/).filter(p => p.trim().length > 0).length;

  // Frequência case-insensitive, mas preservando forma original mais comum
  const freq = {};

  originalWords.forEach(word => {
    const key = word.toLowerCase();

    if (!freq[key]) {
      freq[key] = { total: 0, forms: {} };
    }

    freq[key].total++;
    freq[key].forms[word] = (freq[key].forms[word] || 0) + 1;
  });

  const topWords = Object.entries(freq)
    .sort((a, b) => b[1].total - a[1].total)
    .slice(0, 10)
    .map(([_, data]) => {
      // escolhe a forma mais frequente exatamente como digitada
      return Object.entries(data.forms)
        .sort((a, b) => b[1] - a[1])[0][0];
    });

  let speed = MEDIUMSPEECH;
  let label = 'Médio';

  if (mode === 'fast') { speed = FASTSPEECH; label = 'Rápido'; }
  if (mode === 'slow') { speed = SLOWSPEECH; label = 'Devagar'; }

  const time = formatTime(charCount / speed);

  return { wordCount, charCount, charNoSpace, sentences, paragraphs, topWords, time, label, speed };
}

function renderPage(content = '') {
  return `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Contador de caracteres</title>
<style>
body { font-family: Arial; margin:0; }
header { text-align:center; padding:20px; }
main { display:flex; gap:20px; padding:20px; }

/* Melhor distribuição sem quebrar layout */
main > div:first-child { flex: 2; }
main > .stats { flex: 1; }

textarea {
  width: 100%;
  height: 360px;
  font-size: 18px;
  line-height: 1.5;
  padding: 10px;
  box-sizing: border-box;
}

.stats {
  font-size: 15px;
}
</style>
</head>
<body>
<header><h1>Contador de caracteres</h1></header>
<main>
<div>
<textarea id="text">${content}</textarea><br><br>
<label>Tempo de locução:</label>
<input type="radio" name="mode" value="slow"> Devagar
<input type="radio" name="mode" value="medium" checked> Médio
<input type="radio" name="mode" value="fast"> Rápido
</div>
<div class="stats" id="stats"></div>
</main>

<script>
const textEl = document.getElementById('text');
const statsEl = document.getElementById('stats');
const radios = document.querySelectorAll('input[name="mode"]');

function getMode() {
  return document.querySelector('input[name="mode"]:checked').value;
}

async function update() {
  const text = textEl.value;
  const mode = getMode();

  const res = await fetch('/analyze', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ text, mode })
  });

  const r = await res.json();

statsEl.innerHTML =
  '<p>Quantidade de toques: ' + r.charCount + '</p>' +
  '<p>Quantidade de palavras: ' + r.wordCount + '</p>' +
  '<p>Quantidade de caracteres (exceto espaços): ' + r.charNoSpace + '</p>' +
  '<p>Quantidade de frases: ' + r.sentences + '</p>' +
  '<p>Quantidade de parágrafos: ' + r.paragraphs + '</p>' +
  '<p>Tempo de locução: ' + r.time + '</p>' +
  '<p>Velocidade de locução: ' + r.speed + ' toques/min</p><br>' +
  '<p>Palavras mais frequentes: ' + r.topWords.join(', ') + '</p>';
}

textEl.addEventListener('input', update);
radios.forEach(r => r.addEventListener('change', update));

update();
</script>

</body>
</html>`;
}

// GET
app.get('/', (req, res) => {
  db.get('SELECT text FROM stats WHERE id = 1', [], (err, row) => {
    const content = row ? row.text : '';
    res.send(renderPage(content));
  });
});

// API realtime
app.post('/analyze', (req, res) => {
  const text = req.body.text || '';
  const mode = req.body.mode || 'medium';

  db.run(`INSERT INTO stats (id, text) VALUES (1, ?) ON CONFLICT(id) DO UPDATE SET text=excluded.text`, [text]);

  res.json(analyze(text, mode));
});

app.listen(3000, () => console.log('Rodando em http://localhost:3000'));