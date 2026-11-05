<?php
/**
 * Choice allow-list must keep only a string identical to one configured choice.
 *
 * Option::process() checks choices with in_array() and no strict flag, then
 * take_action() stores or hands the original argument to a callback. These
 * tests drive parse_args() and require every other spelling to be rejected
 * with OPTION_VALUE_ERROR before that happens. They fail while the loose
 * check is in place.
 *
 * Actions that cannot be constructed with type "choice" (store_const,
 * store_true, store_false, append_const, count, help, version) never reach
 * _check_choice(), so they are not covered here.
 *
 * Run from the repository root:
 *     php t/choice_allowlist_test.php
 */

require_once dirname(__DIR__) . '/optparse.php';

/**
 * Records the value a choice callback actually received.
 *
 * Writing the file is the use of the value. A rejection that happens first
 * must not create it.
 */
function choice_allowlist_record($option, $opt_string, $value, $parser, $args) {
    file_put_contents($args[0], json_encode($value));
}

if (isset($argv[1]) && $argv[1] === '--child') {
    $spec = json_decode(base64_decode($argv[2]), true);
    $separator = array_search('--', $argv, true);
    $parse_argv = array_slice($argv, $separator + 1);
    choice_allowlist_child($spec, $parse_argv);
    exit(0);
}

exit(choice_allowlist_main());

/**
 * Parse one argv and print ACCEPTED plus the stored dest when parsing returns.
 * Invalid choices exit inside OptionParser::error() and never reach that print.
 */
function choice_allowlist_child($spec, $parse_argv) {
    $parser = new OptionParser(array(
        'add_help_option' => false,
        'prog' => 'choice-allowlist-test',
    ));

    $settings = $spec['option_strings'];
    $settings['dest'] = 'mode';
    $settings['action'] = $spec['action'];
    $settings['choices'] = $spec['choices'];
    $settings['nargs'] = $spec['nargs'];
    if ($spec['explicit_type']) {
        $settings['type'] = 'choice';
    }
    if ($spec['action'] === 'callback') {
        $settings['callback'] = 'choice_allowlist_record';
        $settings['callback_args'] = array($spec['side_effect']);
    }

    $parser->add_option($settings);
    $values = $parser->parse_args($parse_argv);

    echo "ACCEPTED\n";
    echo json_encode($values->options['mode']), "\n";
}

function choice_allowlist_main() {
    $cases = choice_allowlist_cases();
    $failed = 0;

    foreach ($cases as $case) {
        $result = choice_allowlist_run($case);
        $error = choice_allowlist_assess($case, $result);
        if ($error === null) {
            echo "PASS " . $case['name'] . "\n";
            continue;
        }
        $failed++;
        echo "FAIL " . $case['name'] . "\n";
        echo "  " . $error . "\n";
        echo "  exit=" . $result['exit'] . "\n";
        echo "  stdout=" . json_encode($result['stdout']) . "\n";
        echo "  stderr=" . json_encode($result['stderr']) . "\n";
        echo "  side_effect=" . json_encode($result['side_body']) . "\n";
    }

    $total = count($cases);
    echo "\n" . ($total - $failed) . " passed, " . $failed . " failed, " . $total . " total\n";
    return $failed === 0 ? 0 : 1;
}

function choice_allowlist_cases() {
    $cases = array();

    // Loose numeric spellings. Identical strings of the same choices are
    // listed further down and must still be accepted.
    $spellings = array(
        array('store rejects scientific notation 1e1', array('10', '20'), '1e1', array('--mode'), array('prog', '--mode', '1e1')),
        array('store rejects scientific notation 1E1 via --mode=', array('10'), '1E1', array('--mode'), array('prog', '--mode=1E1')),
        array('store rejects scientific notation 1e+1 via -m', array('10'), '1e+1', array('-m'), array('prog', '-m', '1e+1')),
        array('store rejects scientific notation attached to -m', array('10'), '1E+1', array('-m'), array('prog', '-m1E+1')),
        array('store rejects leading zero 01', array('1'), '01', array('--mode'), array('prog', '--mode', '01')),
        array('store rejects decimal spelling 1.0', array('1'), '1.0', array('--mode'), array('prog', '--mode', '1.0')),
        array('store rejects signed spelling +10', array('10'), '+10', array('--mode'), array('prog', '--mode', '+10')),
        array('store rejects trailing decimal 10.', array('10'), '10.', array('--mode'), array('prog', '--mode', '10.')),
        array('store rejects leading whitespace', array('10'), " 10", array('--mode'), array('prog', '--mode', " 10")),
        array('store rejects trailing whitespace', array('10'), "10 ", array('--mode'), array('prog', '--mode', "10 ")),
        array('store rejects leading newline', array('10'), "\n10", array('--mode'), array('prog', '--mode', "\n10")),
        array('store rejects trailing tab', array('10'), "10\t", array('--mode'), array('prog', '--mode', "10\t")),
        array('store rejects zero with leading zeros', array('0'), '00', array('--mode'), array('prog', '--mode', '00')),
        array('store rejects scientific zero 0e0', array('0'), '0e0', array('--mode'), array('prog', '--mode', '0e0')),
        array('store rejects plain 100 when the choice is 1e2', array('1e2'), '100', array('--mode'), array('prog', '--mode', '100')),
        array('store rejects .5 when the choice is 0.5', array('0.5'), '.5', array('--mode'), array('prog', '--mode', '.5')),
        array('store rejects -.5 when the choice is -0.5', array('-0.5'), '-.5', array('--mode'), array('prog', '--mode', '-.5')),
    );
    foreach ($spellings as $spelling) {
        $cases[] = array(
            'name' => $spelling[0],
            'action' => 'store',
            'nargs' => 1,
            'choices' => $spelling[1],
            'explicit_type' => true,
            'option_strings' => $spelling[3],
            'argv' => $spelling[4],
            'expect' => 'reject',
        );
    }

    // Same check, choices implied by the choices key with no explicit type.
    $cases[] = array(
        'name' => 'implied choice type rejects 1.0',
        'action' => 'store',
        'nargs' => 1,
        'choices' => array('1'),
        'explicit_type' => false,
        'option_strings' => array('--mode'),
        'argv' => array('prog', '--mode', '1.0'),
        'expect' => 'reject',
    );

    // Same check when the configured choices are numbers, not strings.
    // "1e1" is not the integer 10. The argv token must not be stored.
    $cases[] = array(
        'name' => 'store rejects 1e1 against integer choice 10',
        'action' => 'store',
        'nargs' => 1,
        'choices' => array(10, 20),
        'explicit_type' => true,
        'option_strings' => array('--mode'),
        'argv' => array('prog', '--mode', '1e1'),
        'expect' => 'reject',
    );

    // append uses the same check, then pushes the original token.
    $cases[] = array(
        'name' => 'append rejects scientific notation 2e1',
        'action' => 'append',
        'nargs' => 1,
        'choices' => array('10', '20'),
        'explicit_type' => true,
        'option_strings' => array('--mode'),
        'argv' => array('prog', '--mode', '2e1'),
        'expect' => 'reject',
    );
    $cases[] = array(
        'name' => 'append rejects a later loose spelling after an exact choice',
        'action' => 'append',
        'nargs' => 1,
        'choices' => array('10', '20'),
        'explicit_type' => true,
        'option_strings' => array('--mode'),
        'argv' => array('prog', '--mode', '10', '--mode', '1e1'),
        'expect' => 'reject',
    );

    // callback is the use of the value. The recorder must not run.
    $cases[] = array(
        'name' => 'callback is not invoked for scientific notation 1e1',
        'action' => 'callback',
        'nargs' => 1,
        'choices' => array('10'),
        'explicit_type' => true,
        'option_strings' => array('--mode'),
        'argv' => array('prog', '--mode', '1e1'),
        'expect' => 'reject',
    );

    // nargs > 1 takes the array branch in convert_value and checks each element.
    $cases[] = array(
        'name' => 'nargs=2 rejects a loose first value',
        'action' => 'store',
        'nargs' => 2,
        'choices' => array('10', '20'),
        'explicit_type' => true,
        'option_strings' => array('--pair'),
        'argv' => array('prog', '--pair', '1e1', '20'),
        'expect' => 'reject',
    );
    $cases[] = array(
        'name' => 'nargs=2 rejects a loose second value',
        'action' => 'store',
        'nargs' => 2,
        'choices' => array('10', '20'),
        'explicit_type' => true,
        'option_strings' => array('--pair'),
        'argv' => array('prog', '--pair', '10', '2e1'),
        'expect' => 'reject',
    );
    $cases[] = array(
        'name' => 'nargs=2 callback is not invoked when an element is 01',
        'action' => 'callback',
        'nargs' => 2,
        'choices' => array('1', '2'),
        'explicit_type' => true,
        'option_strings' => array('--pair'),
        'argv' => array('prog', '--pair', '01', '2'),
        'expect' => 'reject',
    );

    // Strings that are not loosely equal must stay rejected too. On PHP 8 this
    // already happens; a strict check has to keep rejecting them.
    $cases[] = array(
        'name' => 'store rejects a word outside the list',
        'action' => 'store',
        'nargs' => 1,
        'choices' => array('10', '20'),
        'explicit_type' => true,
        'option_strings' => array('--mode'),
        'argv' => array('prog', '--mode', 'nope'),
        'expect' => 'reject',
    );
    $cases[] = array(
        'name' => 'store rejects 0 against a non-numeric choice list',
        'action' => 'store',
        'nargs' => 1,
        'choices' => array('yes', 'no'),
        'explicit_type' => true,
        'option_strings' => array('--mode'),
        'argv' => array('prog', '--mode', '0'),
        'expect' => 'reject',
    );
    $cases[] = array(
        'name' => 'store rejects a numeric choice with trailing shell metacharacters',
        'action' => 'store',
        'nargs' => 1,
        'choices' => array('10'),
        'explicit_type' => true,
        'option_strings' => array('--mode'),
        'argv' => array('prog', '--mode', '10;id'),
        'expect' => 'reject',
    );

    // Exact members of the list must still be stored unchanged.
    $cases[] = choice_allowlist_accept(
        'store accepts the exact choice 10',
        'store',
        1,
        array('10', '20'),
        true,
        array('--mode'),
        array('prog', '--mode', '10'),
        '10'
    );
    $cases[] = choice_allowlist_accept(
        'store accepts the exact choice via --mode=',
        'store',
        1,
        array('10'),
        true,
        array('--mode'),
        array('prog', '--mode=10'),
        '10'
    );
    $cases[] = choice_allowlist_accept(
        'store accepts the exact choice attached to -m',
        'store',
        1,
        array('10'),
        true,
        array('-m'),
        array('prog', '-m10'),
        '10'
    );
    $cases[] = choice_allowlist_accept(
        'store accepts the exact choice 1',
        'store',
        1,
        array('1'),
        true,
        array('--mode'),
        array('prog', '--mode', '1'),
        '1'
    );
    $cases[] = choice_allowlist_accept(
        'store accepts a non-numeric choice',
        'store',
        1,
        array('yes', 'no'),
        true,
        array('--mode'),
        array('prog', '--mode', 'yes'),
        'yes'
    );
    $cases[] = choice_allowlist_accept(
        'implied choice type accepts the exact choice',
        'store',
        1,
        array('1'),
        false,
        array('--mode'),
        array('prog', '--mode', '1'),
        '1'
    );
    $cases[] = choice_allowlist_accept(
        'append keeps exact choices in order',
        'append',
        1,
        array('10', '20'),
        true,
        array('--mode'),
        array('prog', '--mode', '10', '--mode', '20'),
        array('10', '20')
    );
    $cases[] = choice_allowlist_accept(
        'callback receives the exact choice',
        'callback',
        1,
        array('10'),
        true,
        array('--mode'),
        array('prog', '--mode', '10'),
        '10'
    );
    $cases[] = choice_allowlist_accept(
        'nargs=2 stores both exact choices',
        'store',
        2,
        array('10', '20'),
        true,
        array('--pair'),
        array('prog', '--pair', '10', '20'),
        array('10', '20')
    );
    $cases[] = choice_allowlist_accept(
        'nargs=2 callback receives both exact choices',
        'callback',
        2,
        array('1', '2'),
        true,
        array('--pair'),
        array('prog', '--pair', '1', '2'),
        array('1', '2')
    );

    return $cases;
}

function choice_allowlist_accept($name, $action, $nargs, $choices, $explicit, $option_strings, $argv, $expected) {
    return array(
        'name' => $name,
        'action' => $action,
        'nargs' => $nargs,
        'choices' => $choices,
        'explicit_type' => $explicit,
        'option_strings' => $option_strings,
        'argv' => $argv,
        'expect' => 'accept',
        'expected' => $expected,
    );
}

function choice_allowlist_run($case) {
    $side = tempnam(sys_get_temp_dir(), 'choice');
    unlink($side);

    $spec = array(
        'action' => $case['action'],
        'nargs' => $case['nargs'],
        'choices' => $case['choices'],
        'explicit_type' => $case['explicit_type'],
        'option_strings' => $case['option_strings'],
        'side_effect' => $side,
    );
    $command = array(
        PHP_BINARY,
        __FILE__,
        '--child',
        base64_encode(json_encode($spec)),
        '--',
    );
    foreach ($case['argv'] as $arg) {
        $command[] = $arg;
    }

    $pipes = array();
    $proc = proc_open(
        $command,
        array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        ),
        $pipes
    );
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    $side_exists = is_file($side);
    $side_body = $side_exists ? file_get_contents($side) : null;
    if ($side_exists) {
        unlink($side);
    }

    return array(
        'exit' => $exit,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'side_body' => $side_body,
    );
}

function choice_allowlist_assess($case, $result) {
    if ($result['exit'] === 3) {
        if (strpos($result['stderr'], 'invalid choice') === false) {
            return 'exit 3 did not report an invalid choice';
        }
        if (strpos($result['stdout'], 'ACCEPTED') !== false) {
            return 'the value was stored and then the parser exited';
        }
        if ($result['side_body'] !== null) {
            return 'the choice callback ran before the parser rejected the value';
        }
        if ($case['expect'] === 'reject') {
            return null;
        }
        return 'exact choice was rejected';
    }

    if ($case['expect'] === 'reject') {
        if (strpos($result['stdout'], 'ACCEPTED') !== false) {
            return 'parser accepted a value that is not an identical choice';
        }
        if ($result['side_body'] !== null) {
            return 'choice callback received a value that is not an identical choice';
        }
        return 'parser did not reject the value with OPTION_VALUE_ERROR (3)';
    }

    if ($result['exit'] !== 0) {
        return 'exact choice was not accepted';
    }
    if ($result['side_body'] !== null && $case['action'] !== 'callback') {
        return 'a callback side effect was produced for a non-callback action';
    }

    if ($case['action'] === 'callback') {
        if ($result['side_body'] === null) {
            return 'callback was not invoked for an exact choice';
        }
        $actual = json_decode($result['side_body'], true);
        if ($actual !== $case['expected']) {
            return 'callback received ' . $result['side_body'];
        }
        return null;
    }

    $lines = explode("\n", rtrim($result['stdout'], "\n"));
    if (count($lines) < 2 || $lines[0] !== 'ACCEPTED') {
        return 'parser did not report the stored value';
    }
    $actual = json_decode($lines[1], true);
    if ($actual !== $case['expected']) {
        return 'stored ' . $lines[1] . ' instead of ' . json_encode($case['expected']);
    }
    return null;
}
