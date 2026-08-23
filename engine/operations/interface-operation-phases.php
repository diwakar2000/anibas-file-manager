<?php

/**
 * Base contract implemented by every time-bounded operation phase run by
 * PhaseExecutor (list, assembly, transfer, delete, wrapup, etc.).
 *
 * @package Anibas_File_Manager
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * Abstract base class defining the interface a job phase must implement:
 * execute a bounded chunk of work, report whether it is complete, and name
 * the phase that follows it.
 */
abstract class OperationPhase
{
    abstract public function execute(&$job, &$work_queue, $manager, &$context);
    abstract public function is_complete($work_queue);
    abstract public function next_phase();
}
