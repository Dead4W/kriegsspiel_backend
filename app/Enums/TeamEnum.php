<?php

namespace App\Enums;

enum TeamEnum: string
{
    case ADMIN = 'admin';
    case SPECTATOR = 'spectator';
    case BLUE = 'blue';
    case RED = 'red';
}
