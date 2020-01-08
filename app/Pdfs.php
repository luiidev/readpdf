<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Pdfs extends Model
{
    protected $table = "pdfs";
    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'token',
        'jobid',
        'log',
        'estado'
    ];
}
