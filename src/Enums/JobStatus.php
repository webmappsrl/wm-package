<?php

namespace Wm\WmPackage\Enums;

enum JobStatus: string
{
  case New = 'new';
  case Progress = 'progress';
  case Done = 'done';
  case Error = 'error';
}
