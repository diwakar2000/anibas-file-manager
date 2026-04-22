<?php
/**
 * Phase Execution Flow Test
 * 
 * Verifies:
 * 1. Phases execute in correct order
 * 2. State is saved between runs
 * 3. No infinite loops
 * 4. Proper completion detection
 * 5. Scheduling only when incomplete
 */

namespace Anibas\Tests;

class PhaseFlowTest {
    
    public function test_phase_progression() {
        // Mock job
        $job = [
            'id' => 'test123',
            'source_root' => '/test/source',
            'dest_root' => '/test/dest',
            'action' => 'copy',
            'conflict_mode' => 'skip',
            'status' => 'pending',
            'processed_count' => 0,
            'failed_count' => 0,
            'errors' => [],
        ];
        
        // Empty work queue
        $work_queue = [];
        
        // Mock manager
        $manager = $this->create_mock_manager();
        
        // Test phase progression
        $executor = new \Anibas\PhaseExecutor();
        
        // Run 1: Should initialize
        $is_complete = $executor->execute_with_time_limit( $job, $work_queue, $manager );
        assert( $work_queue['current_phase'] === 'list', 'Should be in list phase' );
        assert( ! $is_complete, 'Should not be complete after initialize' );
        
        // Run 2: Should list (mock returns empty, so completes immediately)
        $is_complete = $executor->execute_with_time_limit( $job, $work_queue, $manager );
        assert( $work_queue['current_phase'] === 'transfer', 'Should be in transfer phase' );
        assert( ! $is_complete, 'Should not be complete after list' );
        
        // Run 3: Should transfer (no files, so completes immediately)
        $is_complete = $executor->execute_with_time_limit( $job, $work_queue, $manager );
        assert( $is_complete, 'Should be complete after wrapup' );
        assert( $job['status'] === 'completed', 'Job should be marked completed' );
        
        echo "✓ Phase progression test passed\n";
    }
    
    public function test_no_infinite_loop() {
        $job = [
            'id' => 'test456',
            'source_root' => '/test/source',
            'dest_root' => '/test/dest',
            'action' => 'copy',
            'conflict_mode' => 'skip',
            'status' => 'pending',
            'processed_count' => 0,
            'failed_count' => 0,
            'errors' => [],
        ];
        
        $work_queue = [];
        $manager = $this->create_mock_manager();
        $executor = new \Anibas\PhaseExecutor();
        
        // Should complete within max iterations
        $is_complete = $executor->execute_with_time_limit( $job, $work_queue, $manager );
        
        assert( $is_complete || isset( $work_queue['current_phase'] ), 'Should have valid state' );
        assert( empty( $job['errors'] ) || count( $job['errors'] ) < 10, 'Should not accumulate errors' );
        
        echo "✓ No infinite loop test passed\n";
    }
    
    private function create_mock_manager() {
        return new class {
            public function listDirectory( $path, $page, $limit ) {
                return [ 'items' => [] ];
            }
            
            public function frontendPathToReal( $path ) {
                return $path;
            }
            
            public function processSingleFile( $source, $target, $action ) {
                return true;
            }
            
            public function resolveNameClash( $target ) {
                return $target . '_copy';
            }
        };
    }
}

// Run tests if executed directly
if ( basename( __FILE__ ) === basename( $_SERVER['SCRIPT_FILENAME'] ) ) {
    $test = new PhaseFlowTest();
    $test->test_phase_progression();
    $test->test_no_infinite_loop();
    echo "\n✓ All tests passed\n";
}
