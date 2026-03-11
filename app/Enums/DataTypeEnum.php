<?php

namespace App\Enums;

enum DataTypeEnum: string
{
    case JSON = 'json';
    case GZ_COMPRESSED = 'gz_compressed';
}
