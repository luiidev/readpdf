<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Aws\S3\S3Client;
use Aws\Textract\TextractClient;
use Illuminate\Support\Str;
use App\Schema;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Aws\Sns\SnsClient;
use Carbon\Carbon;
use App\Pdfs;

class TextractController extends Controller
{
    private $AWS_ACCESS_KEY_ID;
    private $AWS_SECRET_ACCESS_KEY;
    private $AWS_DEFAULT_REGION;
    private $AWS_BUCKET;

    public function __construct()
    {
        $this->AWS_ACCESS_KEY_ID = env('CREDENTIALS_AWS_ACCESS_KEY_ID');
        $this->AWS_SECRET_ACCESS_KEY = env('CREDENTIALS_AWS_SECRET_ACCESS_KEY');
        $this->AWS_DEFAULT_REGION = env('CREDENTIALS_AWS_DEFAULT_REGION');
        $this->AWS_BUCKET = env('CREDENTIALS_AWS_BUCKET');
    }

    public function dashboard(Request $request)
    {
        if (!$request->filled('buscar')) {
            return view('dashboard', [
                'registros' => [],
                'message' => 'Ingrese un prefijo de busqueda.'
            ]);
        }

        $buscar = $request->input('buscar');
        $registros = Pdfs::where('nombre', 'like', "%{$buscar}%")->get();

        return view('dashboard', ['registros' => $registros]);
    }

    public function refreshPdf(Request $request)
    {
        if (!$request->filled('buscar')) {
            return redirect()->route('dashboard', ['buscar' => $request->input('buscar')]);
        }

        $s3 = new S3Client([
            'version'     => 'latest',
            'region'      => $this->AWS_DEFAULT_REGION,
            'credentials' => [
                'key'    => $this->AWS_ACCESS_KEY_ID,
                'secret' => $this->AWS_SECRET_ACCESS_KEY,
            ],
        ]);

        $list = $s3->listObjects([
            'Bucket' => $this->AWS_BUCKET,
            'Prefix' => $request->input('buscar'),
        ]);

        $objects = [];


        foreach ($list['Contents']  as $item) {

            if (!Pdfs::where('nombre', '=', $item['Key'])->exists()) {
                $pdf = new Pdfs();

                $pdf->nombre = $item['Key'];
                $pdf->token = (string) Str::uuid();
                $pdf->estado = 'NUEVO';

                $pdf->save();
            }
        }

        return redirect()->route('dashboard', ['buscar' => $request->input('buscar')]);
    }

    public function startDetectionByDashBoard(Request $request)
    {
        if (!$request->filled('buscar')) {
            return redirect()->route('dashboard', ['buscar' => $request->input('buscar')]);
        }

        $registros = Pdfs::where("estado", "PENDIENTE")->orWhere("estado", "NUEVO")->get();

        $client = new TextractClient([
            'version' => 'latest',
            'region' => $this->AWS_DEFAULT_REGION,
            'credentials' => [
                'key'    => $this->AWS_ACCESS_KEY_ID,
                'secret' => $this->AWS_SECRET_ACCESS_KEY,
            ],
        ]);

        foreach($registros as $registro) {
            try {
                $result = $client->startDocumentTextDetection([
                    'ClientRequestToken' => $registro->token,
                    'DocumentLocation' => [
                        'S3Object' => [
                            'Bucket' => $this->AWS_BUCKET,
                            'Name' => $registro->nombre,
                        ],
                    ],
                    'JobTag' => 'clinica',
                    'NotificationChannel' => [
                        'RoleArn' => 'arn:aws:iam::718833824413:role/TextractRole',
                        'SNSTopicArn' => 'arn:aws:sns:us-east-1:718833824413:AmazonTextractClinica',
                     ],
                ]);

                $registro->estado = "EN_PROGRESO";
                $registro->jobid = $result->get("JobId");
                $registro->save();
            } catch(\Aws\Textract\Exception\TextractException $e) {
                $registro->estado = "PENDIENTE";
                $registro->log = $e->getMessage();
                $registro->save();
            }
        }

        return redirect()->route('dashboard', ['buscar' => $request->input('buscar')]);
    }

    public function getDetection($id, $nombre = "")
    {
        $client = new TextractClient([
            'version' => 'latest',
            'region' => $this->AWS_DEFAULT_REGION,
            'credentials' => [
                'key'    => $this->AWS_ACCESS_KEY_ID,
                'secret' => $this->AWS_SECRET_ACCESS_KEY,
            ],
        ]);

        $result = $client->getDocumentTextDetection([
            'JobId' => $id
        ]);

        $status = $result->get("JobStatus");

        if ($status == 'SUCCEEDED') {

            /**
             * Logica
             */
            $texts = [];
            $removeIds = [];

            foreach ($result->get("Blocks") as $block) {
                if (isset($block["Text"])) {
                    $texts[$block["Id"]] = $block["Text"];

                    if (isset($block["Relationships"])) {
                        foreach ($block["Relationships"] as $relationship) {
                            $removeIds = array_merge($removeIds, $relationship["Ids"]);
                        }
                    }
                }
            }

            foreach ($removeIds as $id) {
                if (isset($texts[$id])) {
                    unset($texts[$id]);
                }
            }
            /**
             * END logica
             */

            /**
             * Persistir data en BD
             */
            logger('Init: '.$id);
            $this->saveReg($texts, $nombre);

            Pdfs::where("nombre", $nombre)->update([
                "estado" => "COMPLETADO"
            ]);

            logger('END: '.$id);

            return [
                "JobStatus" => $status,
                "Texts" => $texts
            ];
        } else {
            logger($id.' JobStatus: '.$status);

            return [
                "JobStatus" =>  $status
            ];
        }
    }

    public function getDetectionBySNS(Request $request)
    {
        try {
            $message = Message::fromRawPostData();

            $validator = new MessageValidator();
            if ($validator->isValid($message)) {
                logger($message['Type']);
                logger(print_r(json_decode($message['Message'], true), true));
                if ($message['Type'] === 'SubscriptionConfirmation') {
                    logger(file_get_contents($message['SubscribeURL']));
                } else if ($message['Type'] === 'Notification') {
                    $snsNotification = json_decode($message['Message'], true);

                    logger($message['Message']);
                    logger($snsNotification["JobId"].' '.$snsNotification["DocumentLocation"]["S3ObjectName"]);

                    return $this->getDetection($snsNotification["JobId"], $snsNotification["DocumentLocation"]["S3ObjectName"]);
                }
            } else {
                logger("Error AWS SNS Notification.");
            }
        } catch(Exception $e) {
            logger($e->getMessage() . ' ' . $e->getFile() . ' ' . $e->getLine());
        }
    }

    private function saveReg($texts, $nombre)
    {
        $values = array_values($texts);

        Schema::create([
            'nombre' => $nombre,
            'col1' => $values[0],
            'col2' => $values[1],
            'col3' => $values[2],
            'col4' => $values[3],
            'col5' => $values[4],
            'col6' => $values[5],
            'col7' => $values[6],
            'col8' => $values[7],
            'col9' => $values[8],
            'col10' => $values[9],
            'col11' => $values[10],
            'col12' => $values[11],
            'col13' => $values[12],
            'col14' => $values[13],
            'col15' => $values[14],
            'col16' => $values[15],
            'col17' => $values[16],
            'col18' => $values[17],
            'col19' => $values[18],
            'col20' => $values[19],
            'col21' => $values[20],
            'col22' => $values[21],
            'col23' => $values[22],
            'col24' => $values[23],
            'col25' => $values[24],
            'col26' => $values[25],
            'col27' => $values[26],
            'col28' => $values[27],
            'col29' => $values[28],
            'col30' => $values[29],
            'col31' => $values[30],
            'col32' => $values[31],
            'col33' => $values[32],
            'col34' => $values[33],
            'col35' => $values[34],
            'col36' => $values[35],
            'col37' => $values[36],
            'col38' => $values[37],
            'col39' => $values[38],
            'col40' => $values[39],
            'col41' => $values[40],
            'col42' => $values[41],
            'col43' => $values[42],
            'col44' => $values[43],
            'col45' => $values[44],
            'col46' => $values[45],
            'col47' => $values[46],
            'col48' => $values[47],
            'col49' => $values[48],
            'col50' => $values[49]
        ]);
    }

    public function snsSbc()
    {
        $client = new SnsClient([
            'version' => 'latest',
            'region' => $this->AWS_DEFAULT_REGION,
            'credentials' => [
                'key'    => $this->AWS_ACCESS_KEY_ID,
                'secret' => $this->AWS_SECRET_ACCESS_KEY,
            ],
        ]);

        // $result = $client->listSubscriptionsByTopic([
        //     'TopicArn' => 'arn:aws:sns:us-east-1:718833824413:SBC-Quellaveco'
        // ]);

        // dd($result);

        // $usuarios =  Pdfs::all();

        // $result = $client->publish([
        //         'Message' => "Amigo, Completa el SBC todos los días en tu Aplicativo y deja la cartilla. Somos equipo para hacer nuestro trabajo más productivo, rápido y evitar accidentes. GyM Concentradora.",
        //         'PhoneNumber' => '+51930654261',
        //     ]);
        // var_dump($result);

        // foreach ($usuarios as $usuario) {
        //     $protocol = 'sms';
        //     $endpoint = '+51'.$usuario->nombre;
        //     $topic = 'arn:aws:sns:us-east-1:718833824413:SBC-Quellaveco';

        //     try {
        //         $result = $client->subscribe([
        //             'Protocol' => $protocol,
        //             'Endpoint' => $endpoint,
        //             'ReturnSubscriptionArn' => true,
        //             'TopicArn' => $topic,
        //         ]);

        //         $usuario->log = "OK";
        //         $usuario->save();
        //     } catch (AwsException $e) {
        //         error_log($e->getMessage());
        //     }
        // }

        return 'sns';
    }
}
