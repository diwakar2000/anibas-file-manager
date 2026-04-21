<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;


abstract class OperationPhase
{
    abstract public function execute(&$job, &$work_queue, $manager, &$context);
    abstract public function is_complete($work_queue);
    abstract public function next_phase();
}
