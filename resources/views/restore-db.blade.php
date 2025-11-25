<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ripristina Database</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .card {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-top: 0;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
            color: #856404;
        }
        .error {
            background: #f8d7da;
            border: 1px solid #dc3545;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
            color: #721c24;
        }
        .success {
            background: #d4edda;
            border: 1px solid #28a745;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
            color: #155724;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            margin-right: 10px;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .loading {
            display: none;
            margin: 20px 0;
            text-align: center;
        }
        .loading.active {
            display: block;
        }
        .output {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
            font-family: monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Ripristina Database</h1>

        @if(session('success'))
            <div class="success">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="error">
                {{ session('error') }}
            </div>
        @endif

        @if(!$dumpExists)
            <div class="error">
                <strong>Errore:</strong> Il file dump '{{ $filename }}' non è stato trovato nella directory storage/backups.
                <br>Assicurati di aver scaricato un backup prima di procedere.
            </div>
        @else
            <div class="warning">
                <strong>Attenzione!</strong> Questa operazione cancellerà completamente il database corrente e lo sostituirà con il contenuto del dump.
                <br><br>
                <strong>File:</strong> {{ $filename }}
                <br>
                <strong>Ambiente:</strong> {{ app()->environment() }}
            </div>

            <form id="restoreForm" method="POST" action="{{ route('restore.db') }}">
                @csrf
                <label style="display: flex; align-items: center; margin: 20px 0;">
                    <input type="checkbox" name="no_wipe" value="1" style="margin-right: 10px;">
                    <span>Salta il wipe del database (non consigliato)</span>
                </label>

                <div class="loading" id="loading">
                    <p>Ripristino in corso... Questo potrebbe richiedere alcuni minuti.</p>
                    <p>Non chiudere questa pagina.</p>
                </div>

                <div id="output" class="output" style="display: none;"></div>

                <div>
                    <button type="submit" class="btn btn-danger" id="restoreBtn">
                        Conferma e Ripristina
                    </button>
                    <a href="{{ url()->previous() }}" class="btn btn-primary">Annulla</a>
                </div>
            </form>
        @endif
    </div>

    <script>
        document.getElementById('restoreForm')?.addEventListener('submit', function(e) {
            if (!confirm('Sei sicuro di voler ripristinare il database? Questa operazione è irreversibile!')) {
                e.preventDefault();
                return false;
            }

            const btn = document.getElementById('restoreBtn');
            const loading = document.getElementById('loading');
            const output = document.getElementById('output');

            btn.disabled = true;
            loading.classList.add('active');
            output.style.display = 'none';

            // Submit form via AJAX to show progress
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch(this.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                }
            })
            .then(response => response.json())
            .then(data => {
                loading.classList.remove('active');
                output.style.display = 'block';
                
                if (data.success) {
                    output.style.background = '#d4edda';
                    output.style.borderColor = '#28a745';
                    output.style.color = '#155724';
                    output.textContent = data.message + '\n\n' + (data.output || '');
                } else {
                    output.style.background = '#f8d7da';
                    output.style.borderColor = '#dc3545';
                    output.style.color = '#721c24';
                    output.textContent = data.error + '\n\n' + (data.output || '');
                }
                
                btn.disabled = false;
            })
            .catch(error => {
                loading.classList.remove('active');
                output.style.display = 'block';
                output.style.background = '#f8d7da';
                output.style.borderColor = '#dc3545';
                output.style.color = '#721c24';
                output.textContent = 'Errore: ' + error.message;
                btn.disabled = false;
            });
        });
    </script>
</body>
</html>


