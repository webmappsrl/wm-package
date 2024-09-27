<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Esporta CSV - Seleziona Filtri</title>
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
            max-width: 500px;
            width: 90%;
            text-align: center;
        }

        h3 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #007bff;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .filter-group {
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }

        select {
            padding: 10px;
            margin-top: 4px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
            background-color: #fafafa;
            outline: none;
            width: 100%;
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
        <h3>Seleziona i Filtri per {{ $models[$model]['label'] }}</h3>

        <form action="{{ route('export.model') }}" method="POST">
            @csrf
            <input type="hidden" name="model" value="{{ $model }}">

            @foreach ($models[$model]['available_filters'] as $filter)
                <div class="filter-group">
                    <label for="{{ $filter['field'] }}">{{ $filter['label'] }}</label>
                    <select name="filters[{{ $filter['field'] }}]" id="{{ $filter['field'] }}">
                        <option value="">Seleziona {{ strtolower($filter['label']) }}</option>
                        @foreach ($filter['options'] as $option)
                            <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach

            <button type="submit">Esporta</button>
        </form>
    </div>

</body>

</html>
