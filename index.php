<?php
// index.php

// Banco SQLite
$db = new SQLite3('word-counter-web-db.sqlite');
$db->exec("CREATE TABLE IF NOT EXISTS stats (
    id INTEGER PRIMARY KEY,
    text TEXT
)");

// Constantes
const FASTSPEECH = 950;
const MEDIUMSPEECH = 920;
const SLOWSPEECH = 890;

function formatTime($minutesFloat) {
    $totalSeconds = ceil($minutesFloat * 60);

    if ($totalSeconds < 60) return $totalSeconds . 's';

    $h = floor($totalSeconds / 3600);
    $m = floor(($totalSeconds % 3600) / 60);
    $s = $totalSeconds % 60;

    return ($h > 0 ? $h . 'h ' : '') . str_pad($m, 2, '0', STR_PAD_LEFT) . 'min ' . str_pad($s, 2, '0', STR_PAD_LEFT) . 's';
}

function analyze($text, $mode) {
    preg_match_all('/\\p{L}+/u', $text, $matches);
    $originalWords = $matches[0] ?? [];

    $wordCount = count($originalWords);
    $charCount = mb_strlen($text);
    $charNoSpace = mb_strlen(preg_replace('/\\s/u', '', $text));
    $sentences = count(array_filter(preg_split('/[.!?]+/', $text), fn($s) => trim($s) !== ''));
    $paragraphs = count(array_filter(preg_split('/\\n+/', $text), fn($p) => trim($p) !== ''));

    $freq = [];

    foreach ($originalWords as $word) {
        $key = mb_strtolower($word);

        if (!isset($freq[$key])) {
            $freq[$key] = ['total' => 0, 'forms' => []];
        }

        $freq[$key]['total']++;
        $freq[$key]['forms'][$word] = ($freq[$key]['forms'][$word] ?? 0) + 1;
    }

    uasort($freq, fn($a, $b) => $b['total'] <=> $a['total']);

    $topWords = [];
    foreach (array_slice($freq, 0, 10) as $data) {
        arsort($data['forms']);
        $topWords[] = array_key_first($data['forms']);
    }

    $speed = MEDIUMSPEECH;
    $label = 'Médio';

    if ($mode === 'fast') { $speed = FASTSPEECH; $label = 'Rápido'; }
    if ($mode === 'slow') { $speed = SLOWSPEECH; $label = 'Devagar'; }

    $time = formatTime($charCount / $speed);

    return [
        'wordCount' => $wordCount,
        'charCount' => $charCount,
        'charNoSpace' => $charNoSpace,
        'sentences' => $sentences,
        'paragraphs' => $paragraphs,
        'topWords' => $topWords,
        'time' => $time,
        'label' => $label,
        'speed' => $speed
    ];
}

// =========================
// ROTEAMENTO CORRIGIDO
// =========================
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($action === 'analyze' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $text = $input['text'] ?? '';
    $mode = $input['mode'] ?? 'medium';

    $stmt = $db->prepare("INSERT INTO stats (id, text) VALUES (1, :text)
        ON CONFLICT(id) DO UPDATE SET text = excluded.text");
    $stmt->bindValue(':text', $text, SQLITE3_TEXT);
    $stmt->execute();

    header('Content-Type: application/json');
    echo json_encode(analyze($text, $mode));
    exit;
}

// Página principal
$result = $db->querySingle("SELECT text FROM stats WHERE id = 1");
$content = $result ? $result : '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Contador de caracteres</title>
<style>
body { font-family: Arial; margin:0; }
header { text-align:center; padding:20px; }
main { display:flex; gap:20px; padding:20px; }
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
.stats { font-size: 15px; }
</style>
</head>
<body>
<header><h1>Contador de caracteres</h1></header>
<main>
<div>
<textarea id="text"><?php echo htmlspecialchars($content); ?></textarea><br><br>
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

  const res = await fetch('index.php?action=analyze', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ text, mode })
  });

  const r = await res.json();

  statsEl.innerHTML = `
    <p>Quantidade de toques: ${r.charCount}</p>
    <p>Quantidade de palavras: ${r.wordCount}</p>
    <p>Quantidade de caracteres (exceto espaços): ${r.charNoSpace}</p>
    <p>Quantidade de frases: ${r.sentences}</p>
    <p>Quantidade de parágrafos: ${r.paragraphs}</p>
    <p>Tempo de locução: ${r.time}</p>
    <p>Velocidade de locução: ${r.speed} toques/min</p>
    <br>
    <p>Palavras mais frequentes: ${r.topWords.join(', ')}</p>
  `;
}

textEl.addEventListener('input', update);
radios.forEach(r => r.addEventListener('change', update));

update();
</script>
</body>
</html>