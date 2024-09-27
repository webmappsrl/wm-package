<!-- resources/views/exports/select-model.blade.php -->
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Esporta CSV - Seleziona Modello</title>
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background-color: #f4f4f9;
            font-family: Arial, sans-serif;
            color: #333;
        }

        .container {
            background: #fff;
            padding: 30px;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            max-width: 400px;
            width: 90%;
            text-align: center;
        }

        h1 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #007bff;
        }

        form {
            display: flex;
            flex-direction: column;
        }

        label {
            margin-bottom: 8px;
            font-weight: bold;
            text-align: left;
        }

        select {
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
            background-color: #fafafa;
            outline: none;
        }

        button {
            padding: 12px;
            border: none;
            background-color: #007bff;
            color: white;
            font-size: 16px;
            cursor: pointer;
            border-radius: 4px;
            transition: background-color 0.3s ease;
        }

        button:hover {
            background-color: #0056b3;
        }
    </style>
</head>

<body>

    <div class="container">
        <h1>Esporta CSV - Seleziona il Modello</h1>

        <form action="{{ route('export.model-selection') }}" method="POST">
            @csrf
            <label for="model">Seleziona il Modello:</label>
            <select name="model" id="model" required>
                <option value="">Seleziona un modello</option>
                @foreach ($models as $model => $config)
                    <option value="{{ $model }}">{{ $config['label'] }}</option>
                @endforeach
            </select>

            <button type="submit">Stampa CSV</button>
        </form>
    </div>

</body>

</html>
