<?php

namespace App\Socket\Callbacks;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

abstract class AbstractSocketCallback
{

    public function __construct(
        protected OutputStyle $output
    ) {
    }

    public function info(string $string)
    {
        $this->line($string, 'info');
    }

    public function error($string)
    {
        $this->line($string, 'error');
    }

    public function warning($string)
    {
        if (! $this->output->getFormatter()->hasStyle('warning')) {
            $style = new OutputFormatterStyle('yellow');

            $this->output->getFormatter()->setStyle('warning', $style);
        }

        $this->line($string, 'warning');
    }

    public function line(string $string, $style = null)
    {
        $title = str_pad(mb_strtoupper($style), 7);
        $styled = $style ? "<$style>[$title] $string</$style>" : $string;

        $this->output->writeln($styled);
    }

}
