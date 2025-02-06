<!DOCTYPE html>
<html>

<head>
    <title>Top Ten Users</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .highlight {
            background-color: yellow !important;
        }

        .poi-container {
            max-height: 200px;
            /* Imposta un'altezza massima per la cella */
            overflow-y: auto;
            /* Aggiungi uno scrolling verticale */
        }

        .poi img,
        .img-thumbnail {
            max-width: 50px;
            max-height: 50px;
            display: block;
            margin: 0 auto;
        }

        .row {
            max-height: 100px;
        }

        tr {
            height: 100px;
            /* Imposta un'altezza massima per ogni riga */
        }
    </style>
</head>

<body class="container my-4">
    <h1 class="mb-4">{{$app->name}} Top Ten Users</h1>
    @if($app->classification_show)
    <div class="table-responsive">
        <table id="rankingTable" class="table table-bordered table-striped">
            <thead class="thead-light">
                <tr>
                    <th>Position</th>
                    <th>User ID (Email)</th>
                    <th>Score</th>
                    <th>POI Details</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($topTen as $userId => $pois)
                @php
                $userEmail = App\Models\User::find($userId)->name ?? 'Unknown';
                $score = count($pois);
                @endphp
                <tr id="user-{{ $userId }}">
                    <td>{{ $loop->iteration }}</td>
                    <td class="user-email">
                        <a href="#user-{{ $userId }}">{{ $userEmail }}</a>
                    </td>
                    <td>{{ $score }}</td>
                    <td class="poi-container">
                        <div class="row">
                            @foreach ($pois as $index => $poi)
                            <div class="col-12 mb-2 d-flex align-items-center">
                                <div class="font-weight-bold mr-2">{{ $index + 1 }}) {{ $poi['ec_poi']['name'] }}</div>
                            </div>
                            @endforeach
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="alert alert-warning" role="alert">
        Nessuna gara è in corso.
    </div>
    @endif
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>

</html>