<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Uploaded = 'uploaded';
    case Parsing = 'parsing';
    case Embedding = 'embedding';
    case Ready = 'ready';
    case Failed = 'failed';
}
