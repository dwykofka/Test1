<?php
// Simple network diagnostics dashboard

function respond(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function validateTarget(string $target): bool {
    return $target !== '' && preg_match('/^[A-Za-z0-9_.:-]+$/', $target) === 1;
}

function validateRecordType(string $type): string {
    $type = strtoupper($type);
    return preg_match('/^[A-Z0-9]+$/', $type) ? $type : 'A';
}

function findCommand(array $candidates): ?string {
    foreach ($candidates as $command) {
        $path = trim(shell_exec('command -v ' . escapeshellarg($command)) ?? '');
        if ($path !== '') {
            return $path;
        }
    }
    return null;
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);
        return substr($haystack, -$len) === $needle;
    }
}

function runShell(string $command): array {
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    return ['output' => implode("\n", $output), 'status' => $status];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tool'])) {
    $tool = $_POST['tool'];
    $target = trim($_POST['target'] ?? '');
    $recordType = isset($_POST['record']) ? validateRecordType($_POST['record']) : 'A';

    $response = ['success' => false, 'title' => 'Request failed', 'body' => 'Unsupported request.'];

    if (in_array($tool, ['ping', 'traceroute', 'dns', 'whois', 'mx'], true) && !validateTarget($target)) {
        respond(['success' => false, 'title' => 'Invalid target', 'body' => 'Only letters, numbers, dots, colons, underscores, and dashes are allowed.']);
    }

    switch ($tool) {
        case 'ping':
            $cmd = 'ping -c 4 ' . escapeshellarg($target);
            $result = runShell($cmd);
            $response = ['success' => $result['status'] === 0, 'title' => 'Ping results', 'body' => $result['output']];
            break;

        case 'traceroute':
            $tracerCmd = findCommand(['traceroute', 'tracepath']);
            if ($tracerCmd === null) {
                $response = ['success' => false, 'title' => 'Traceroute unavailable', 'body' => 'No traceroute-compatible command found on this server.'];
                break;
            }
            $cmd = $tracerCmd . ' ' . escapeshellarg($target);
            $result = runShell($cmd);
            $response = ['success' => $result['status'] === 0, 'title' => 'Traceroute', 'body' => $result['output']];
            break;

        case 'dns':
            $dig = findCommand(['dig']);
            if ($dig === null) {
                $response = ['success' => false, 'title' => 'DNS lookup unavailable', 'body' => 'The "dig" command is not available on this server.'];
                break;
            }
            $cmd = $dig . ' ' . escapeshellarg($target) . ' ' . escapeshellarg($recordType) . ' +nocmd +noall +answer';
            $result = runShell($cmd);
            $response = ['success' => true, 'title' => 'DNS lookup', 'body' => $result['output'] ?: 'No records returned.'];
            break;

        case 'mx':
            $dig = findCommand(['dig']);
            if ($dig === null) {
                $response = ['success' => false, 'title' => 'MX lookup unavailable', 'body' => 'The "dig" command is not available on this server.'];
                break;
            }
            $cmd = $dig . ' ' . escapeshellarg($target) . ' MX +nocmd +noall +answer';
            $result = runShell($cmd);
            $response = ['success' => true, 'title' => 'MX records', 'body' => $result['output'] ?: 'No MX records returned.'];
            break;

        case 'whois':
            $whois = findCommand(['whois']);
            if ($whois === null) {
                $response = ['success' => false, 'title' => 'Whois unavailable', 'body' => 'The "whois" command is not available on this server.'];
                break;
            }
            $cmd = $whois . ' ' . escapeshellarg($target);
            $result = runShell($cmd);
            $response = ['success' => true, 'title' => 'Whois', 'body' => $result['output']];
            break;

        case 'speedtest':
            $speedtest = findCommand(['speedtest', 'speedtest-cli', 'fast']);
            if ($speedtest === null) {
                $response = ['success' => false, 'title' => 'Speed test unavailable', 'body' => 'No speed test CLI found. Please install Ookla Speedtest or speedtest-cli.'];
                break;
            }
            if (str_ends_with($speedtest, 'fast')) {
                $cmd = $speedtest . ' --upload';
            } elseif (str_contains($speedtest, 'speedtest-cli')) {
                $cmd = $speedtest . ' --simple';
            } else {
                $cmd = $speedtest . ' --accept-license --accept-gdpr -f json';
            }
            $result = runShell($cmd);
            $response = ['success' => $result['status'] === 0, 'title' => 'Speed test', 'body' => $result['output'] ?: 'No output returned.'];
            break;

        default:
            $response = ['success' => false, 'title' => 'Unknown tool', 'body' => 'The requested tool is not supported.'];
            break;
    }

    respond($response);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Ops Console</title>
    <style>
        :root {
            --bg: #0d1117;
            --panel: rgba(255, 255, 255, 0.04);
            --panel-strong: rgba(255, 255, 255, 0.08);
            --accent: #7ad7f0;
            --accent-2: #a5e65a;
            --text: #e6edf3;
            --muted: #94a3b8;
            --glow: 0 15px 50px rgba(122, 215, 240, 0.25);
        }

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-4px); }
            100% { transform: translateY(0px); }
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', 'Inter', system-ui, -apple-system, sans-serif;
            background: radial-gradient(circle at 20% 20%, #112233, #0b0e14 55%),
                        radial-gradient(circle at 80% 10%, rgba(122, 215, 240, 0.15), transparent 35%),
                        radial-gradient(circle at 60% 70%, rgba(165, 230, 90, 0.2), transparent 40%),
                        #0b0e14;
            color: var(--text);
        }

        .desktop {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            padding: 40px;
        }

        .dock {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 12px 20px;
            display: flex;
            gap: 14px;
            box-shadow: var(--glow);
            backdrop-filter: blur(14px);
        }

        .window {
            background: linear-gradient(145deg, var(--panel), rgba(255,255,255,0.02));
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 18px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }

        .window::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 20% 20%, rgba(122, 215, 240, 0.15), transparent 40%),
                        radial-gradient(circle at 80% 10%, rgba(165, 230, 90, 0.15), transparent 30%);
            pointer-events: none;
        }

        .window-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .window-header .lights {
            display: flex;
            gap: 6px;
        }

        .light {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.2);
        }
        .light.red { background: #ff5f57; }
        .light.yellow { background: #ffbd2e; }
        .light.green { background: #28c840; }

        .app-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(122, 215, 240, 0.25), rgba(165, 230, 90, 0.2));
            display: grid;
            place-items: center;
            font-size: 28px;
            box-shadow: var(--glow);
        }

        .title {
            font-size: 18px;
            font-weight: 700;
        }

        .muted { color: var(--muted); }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 14px;
        }

        .app-button {
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            background: var(--panel);
            padding: 16px;
            text-align: left;
            color: var(--text);
            cursor: pointer;
            transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
            display: grid;
            gap: 8px;
            animation: float 6s ease-in-out infinite;
        }

        .app-button:hover {
            transform: translateY(-4px);
            border-color: rgba(122, 215, 240, 0.7);
            box-shadow: var(--glow);
        }

        .small {
            font-size: 12px;
            color: var(--muted);
        }

        form {
            display: grid;
            gap: 10px;
        }

        label {
            font-size: 13px;
            color: var(--muted);
        }

        input, select {
            background: var(--panel-strong);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 12px 14px;
            color: var(--text);
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        input:focus, select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(122, 215, 240, 0.2);
        }

        button[type="submit"] {
            background: linear-gradient(135deg, var(--accent), #5fb8f6);
            border: none;
            color: #041220;
            border-radius: 12px;
            padding: 12px 16px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            box-shadow: 0 10px 30px rgba(122, 215, 240, 0.4);
        }

        button[type="submit"]:hover {
            transform: translateY(-2px);
        }

        .status-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 18px;
            background: rgba(255, 255, 255, 0.05);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            color: var(--muted);
        }

        .console {
            background: #0b0f16;
            border: 1px solid rgba(122, 215, 240, 0.35);
            border-radius: 12px;
            padding: 16px;
            font-family: 'SFMono-Regular', Consolas, Menlo, monospace;
            color: #9ae8ff;
            min-height: 180px;
            white-space: pre-wrap;
            overflow: auto;
            box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.35);
        }

        .badge {
            padding: 6px 10px;
            background: rgba(122, 215, 240, 0.18);
            border-radius: 10px;
            color: #a5e65a;
            border: 1px solid rgba(122, 215, 240, 0.35);
            font-size: 12px;
            font-weight: 700;
        }

        .dock button {
            background: transparent;
            border: none;
            color: var(--text);
            font-weight: 600;
            padding: 10px 14px;
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.15s ease, transform 0.15s ease;
        }

        .dock button:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: var(--muted);
        }
    </style>
</head>
<body>
    <div class="status-bar">
        <div class="pill"><span>🛰️</span><span>Network Operations Desktop</span></div>
        <div class="pill"><span>🛡️</span><span>Local tools preferred</span></div>
    </div>

    <div class="desktop">
        <div class="window" id="actions">
            <div class="window-header">
                <div class="lights">
                    <span class="light red"></span>
                    <span class="light yellow"></span>
                    <span class="light green"></span>
                </div>
                <span class="title">Control Center</span>
            </div>
            <p class="muted">Choose a diagnostic. Each tile is wired to server-side binaries so everything runs locally.</p>
            <div class="grid">
                <button class="app-button" data-tool="speedtest">
                    <div class="app-icon">⚡</div>
                    <div>
                        <div class="title">Speed Test</div>
                        <div class="small">Measure upload/download using installed CLI</div>
                    </div>
                </button>
                <button class="app-button" data-tool="ping">
                    <div class="app-icon">📡</div>
                    <div>
                        <div class="title">Ping</div>
                        <div class="small">Reachability with 4 ICMP probes</div>
                    </div>
                </button>
                <button class="app-button" data-tool="traceroute">
                    <div class="app-icon">🛰️</div>
                    <div>
                        <div class="title">Traceroute</div>
                        <div class="small">Hop-by-hop path discovery</div>
                    </div>
                </button>
                <button class="app-button" data-tool="dns">
                    <div class="app-icon">🧭</div>
                    <div>
                        <div class="title">DNS Lookup</div>
                        <div class="small">Query records with dig</div>
                    </div>
                </button>
                <button class="app-button" data-tool="mx">
                    <div class="app-icon">📬</div>
                    <div>
                        <div class="title">MX Scan</div>
                        <div class="small">Mail exchangers for a domain</div>
                    </div>
                </button>
                <button class="app-button" data-tool="whois">
                    <div class="app-icon">🗃️</div>
                    <div>
                        <div class="title">Whois</div>
                        <div class="small">Ownership and registration data</div>
                    </div>
                </button>
            </div>
        </div>

        <div class="window">
            <div class="window-header">
                <div class="lights">
                    <span class="light red"></span>
                    <span class="light yellow"></span>
                    <span class="light green"></span>
                </div>
                <span class="title">Command Palette</span>
                <span class="badge" id="tool-label">Select a tool</span>
            </div>
            <form id="command-form">
                <div>
                    <label for="target">Target host / IP</label>
                    <input type="text" id="target" name="target" placeholder="e.g. example.com" autocomplete="off">
                </div>
                <div id="record-row" style="display:none;">
                    <label for="record">DNS record type</label>
                    <select name="record" id="record">
                        <option value="A">A</option>
                        <option value="AAAA">AAAA</option>
                        <option value="CNAME">CNAME</option>
                        <option value="TXT">TXT</option>
                        <option value="NS">NS</option>
                        <option value="MX">MX</option>
                        <option value="CAA">CAA</option>
                        <option value="SOA">SOA</option>
                    </select>
                </div>
                <button type="submit">Run</button>
            </form>
        </div>

        <div class="window">
            <div class="window-header">
                <div class="lights">
                    <span class="light red"></span>
                    <span class="light yellow"></span>
                    <span class="light green"></span>
                </div>
                <span class="title">Results Terminal</span>
            </div>
            <div class="console" id="output">Select a tool to begin.</div>
        </div>
    </div>

    <div class="dock">
        <button type="button" id="clear">Clear</button>
        <button type="button" id="sample">Use sample host</button>
    </div>

    <script>
        const buttons = document.querySelectorAll('.app-button');
        const form = document.getElementById('command-form');
        const output = document.getElementById('output');
        const toolLabel = document.getElementById('tool-label');
        const recordRow = document.getElementById('record-row');
        const targetInput = document.getElementById('target');
        let selectedTool = null;

        function setTool(tool) {
            selectedTool = tool;
            toolLabel.textContent = tool ? tool.toUpperCase() : 'Select a tool';
            recordRow.style.display = tool === 'dns' ? 'block' : 'none';
            if (tool === 'speedtest') {
                targetInput.value = '';
                targetInput.disabled = true;
                targetInput.placeholder = 'Not required';
            } else {
                targetInput.disabled = false;
                targetInput.placeholder = 'e.g. example.com';
            }
        }

        buttons.forEach(btn => {
            btn.addEventListener('click', () => {
                buttons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                setTool(btn.dataset.tool);
            });
        });

        document.getElementById('clear').addEventListener('click', () => {
            output.textContent = 'Select a tool to begin.';
        });

        document.getElementById('sample').addEventListener('click', () => {
            targetInput.value = 'example.com';
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!selectedTool) {
                output.textContent = 'Please select a tool first.';
                return;
            }
            const formData = new FormData(form);
            formData.append('tool', selectedTool);
            output.textContent = 'Running ' + selectedTool + '…';

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                output.textContent = data.title + "\n\n" + (data.body || 'No output');
            } catch (err) {
                output.textContent = 'Something went wrong while contacting the server.';
            }
        });
    </script>
</body>
</html>
