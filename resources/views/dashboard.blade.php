<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>

    <link href="https://fonts.googleapis.com/css?family=Nunito:200,600" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
    <style type="text/css">
        .reglist {
            font-size: 13px;
        }

        .badge {
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row">
            <div class="col-12 mb-3 mt-3 text-center">
                <h3>Dashboard Textract</h3>
            </div>
        </div>
    </div>
    <div class="container">
        <div class="row">
            <div class="col-12 text-center">

            </div>
        </div>
        @isset($message)
        <div class="row mt-4">
            <div class="col-12">
                <p class="alert alert-danger m-0">{{ $message }}</p>
            </div>
        </div>
        @endif
        <div class="row mt-4">
            <div class="col-4">
                <input id="buscar" type="text" class="form-control form-control-sm border-success" value="{{ Request::input('buscar') }}" name="prefix" placeholder="Prefijo carpeta o nombre de archivo">
            </div>
            <div class="col-3">
                <button id="btnBuscar" class="btn btn-sm btn-success">Buscar</button>
            </div>
            <div class="col-5">
                <button id="btnProcesar" class="btn btn-sm btn-outline-info float-right ml-3">Procesar pendientes</button>
                <button id="btnVerificar" class="btn btn-sm btn-outline-primary float-right">Verificar nuevos PDF</button>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <table class="table table-striped table-sm table-hover mt-2">
                    <thead>
                        <tr>
                            <th class="bg-success text-white border-0">Nombre</th>
                            <th class="bg-success text-white border-0" class="text-center" style="width: 100px;">Estado</th>
                            <th class="bg-success text-white border-0" style="width: 300px;">Job ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($registros as $registro)
                        <tr class="reglist">
                            <td>{{ $registro->nombre }}</td>
                            <td class="text-center">
                                @if($registro->estado == 'NUEVO')
                                    <span class="badge badge-info display-3">{{ $registro->estado }}</span>
                                @elseif($registro->estado == 'PENDIENTE')
                                    <span class="badge badge-danger display-3">{{ $registro->estado }}</span>
                                @elseif($registro->estado == 'COMPLETADO')
                                    <span class="badge badge-success display-3">{{ $registro->estado }}</span>
                                @elseif($registro->estado == 'EN_PROGRESO')
                                    <span class="badge badge-warning display-3">{{ $registro->estado }}</span>
                                @else
                                    <span class="badge display-3">{{ $registro->estado }}</span>
                                @endif
                            </td>
                            <td>{{ $registro->jobid }}</td>
                        </tr>
                        @endforeach
                        @if(count($registros) === 0)
                            <tr class="reglist">
                                <td class="text-center" colspan="3">
                                    <h6>Sin registros</h6>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
    <script type="text/javascript">
        $('#btnBuscar').on('click', function() {
            redirect();
        });

        $('#btnVerificar').on('click', function() {
            redirect('{{ route('refresh') }}');
        });

        $('#btnProcesar').on('click', function() {
            redirect('{{ route('analize') }}');
        });

        $('#buscar').on('keypress',function(e) {
            if(e.which == 13) {
                redirect();
            }
        });

        function redirect(url) {
            var buscar = $('#buscar').val();

            url = url || '{{ url("/") }}';

            location.href = `${url}/?buscar=${buscar}`;
        }
    </script>
</body>
</html>
