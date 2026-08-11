<?php

namespace EYOND\LaravelHttpReplay;

enum ReplayMode: string
{
    case Sequential = 'sequential';
    case Reusable = 'reusable';
}
