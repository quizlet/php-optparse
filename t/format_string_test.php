<?php
/**
 * Spec for fprintf format-string handling in OptionParser.
 *
 * These tests fail while usage, help, version, and error text are passed to
 * fprintf as the format string. They pass once each of those calls uses a
 * constant format and passes the text as an argument, so percent signs from
 * argv or from parser text are printed as data.
 *
 * Run:
 *   php t/format_string_test.php
 *
 * Not covered, because the untrusted value never reaches fprintf:
 *   - A value attached to an option that takes none (--flag=%s) is rejected
 *     in _process_long_option before the value is copied into the message.
 *     The option name on that path is covered separately.
 *   - Positional arguments are stored and are not formatted.
 *   - optparse.php has no printf or sprintf calls. The four fprintf calls are
 *     print_usage, print_help, print_version, and error.
 */

function format_string_reject_value($option, $opt_string, $value, $parser, $callback_args) {
    throw new OptionValueError("rejected " . $value);
}

if (isset($argv[1]) && $argv[1] === "--child") {
    require_once dirname(__DIR__) . "/optparse.php";
    format_string_child($argv[2]);
    fwrite(STDERR, "child returned without exiting: " . $argv[2] . "\n");
    exit(97);
}

require_once dirname(__DIR__) . "/optparse.php";
exit(format_string_run_tests() ? 0 : 1);

function format_string_child($case) {
    switch ($case) {
    case "unknown_long_percent_s":
        $parser = new OptionParser();
        $parser->parse_args(array("prog", "--%s"));
        return;
    case "unknown_long_escaped_percent":
        $parser = new OptionParser();
        $parser->parse_args(array("prog", "--%%"));
        return;
    case "unknown_short_trailing_percent":
        $parser = new OptionParser();
        $parser->parse_args(array("prog", "-%"));
        return;
    case "invalid_int_percent_s":
        $parser = new OptionParser();
        $parser->add_option(array("--num", "dest" => "num", "type" => "int"));
        $parser->parse_args(array("prog", "--num", "%s"));
        return;
    case "invalid_int_trailing_percent":
        $parser = new OptionParser();
        $parser->add_option(array("--num", "dest" => "num", "type" => "int"));
        $parser->parse_args(array("prog", "--num", "100%"));
        return;
    case "invalid_long_percent_s":
        $parser = new OptionParser();
        $parser->add_option(array("--num", "dest" => "num", "type" => "long"));
        $parser->parse_args(array("prog", "--num", "%s"));
        return;
    case "invalid_float_percent_s":
        $parser = new OptionParser();
        $parser->add_option(array("--rate", "dest" => "rate", "type" => "float"));
        $parser->parse_args(array("prog", "--rate=%s"));
        return;
    case "invalid_choice_percent_s":
        $parser = new OptionParser();
        $parser->add_option(array(
            "--color",
            "dest" => "color",
            "type" => "choice",
            "choices" => array("red", "blue"),
        ));
        $parser->parse_args(array("prog", "--color", "%s"));
        return;
    case "short_attached_invalid_int":
        $parser = new OptionParser();
        $parser->add_option(array("-n", "dest" => "num", "type" => "int"));
        $parser->parse_args(array("prog", "-n%s"));
        return;
    case "callback_value_percent_s":
        $parser = new OptionParser();
        $parser->add_option(array(
            "--item",
            "action" => "callback",
            "callback" => "format_string_reject_value",
            "type" => "string",
        ));
        $parser->parse_args(array("prog", "--item", "%s"));
        return;
    case "missing_argument_option_name_percent_s":
        $parser = new OptionParser();
        $parser->add_option(array("--%s", "dest" => "item", "type" => "string"));
        $parser->parse_args(array("prog", "--%s"));
        return;
    case "unexpected_value_option_name_percent_s":
        $parser = new OptionParser(array("add_help_option" => false));
        $parser->add_option(array("--%s", "action" => "store_true", "dest" => "flag"));
        $parser->parse_args(array("prog", "--%s=1"));
        return;
    case "direct_error_percent_s":
        $parser = new OptionParser();
        $parser->error("bad %s value", 4);
        return;
    case "usage_prog_percent_s_on_error":
        $parser = new OptionParser(array("prog" => "%s"));
        $parser->parse_args(array("prog", "--bogus"));
        return;
    case "help_action_description_percent_s":
        $parser = new OptionParser(array("description" => "hello %s"));
        $parser->parse_args(array("prog", "--help"));
        return;
    case "version_action_percent_s":
        $parser = new OptionParser(array("version" => "1.2 %s"));
        $parser->parse_args(array("prog", "--version"));
        return;
    case "version_action_prog_percent_s":
        $parser = new OptionParser(array("version" => "%prog", "prog" => "%s"));
        $parser->parse_args(array("prog", "--version"));
        return;
    case "benign_unknown_option":
        $parser = new OptionParser();
        $parser->parse_args(array("prog", "--bogus"));
        return;
    case "flag_value_not_in_message":
        $parser = new OptionParser(array("add_help_option" => false));
        $parser->add_option(array("--flag", "action" => "store_true", "dest" => "flag"));
        $parser->parse_args(array("prog", "--flag=%s"));
        return;
    default:
        fwrite(STDERR, "unknown child case: $case\n");
        exit(98);
    }
}

function format_string_script_name() {
    return basename(__FILE__);
}

function format_string_usage_line() {
    return "Usage: " . format_string_script_name() . " [options]\n";
}

function format_string_error_stderr($text) {
    return format_string_usage_line()
        . format_string_script_name() . ": error: " . $text . "\n";
}

function format_string_spawn($case) {
    $command = array(PHP_BINARY, __FILE__, "--child", $case);
    $pipes = array();
    $process = proc_open(
        $command,
        array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w"),
        ),
        $pipes
    );
    if (! is_resource($process)) {
        return array(96, "", "proc_open failed");
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);
    return array($exit_code, $stdout, $stderr);
}

function format_string_format_leak($text) {
    $markers = array(
        "ArgumentCountError",
        "ValueError",
        "Fatal error",
        "Stack trace",
        "PHP Warning",
        "Too few arguments",
        "optparse.php",
    );
    foreach ($markers as $marker) {
        if (strpos($text, $marker) !== false) {
            return $marker;
        }
    }
    return "";
}

function format_string_run_tests() {
    $failures = 0;
    $passes = 0;
    $cases = array(
        array(
            "unknown_long_percent_s",
            1,
            "",
            format_string_error_stderr("No such option: --%s"),
        ),
        array(
            "unknown_long_escaped_percent",
            1,
            "",
            format_string_error_stderr("No such option: --%%"),
        ),
        array(
            "unknown_short_trailing_percent",
            1,
            "",
            format_string_error_stderr("No such option: -%"),
        ),
        array(
            "invalid_int_percent_s",
            3,
            "",
            format_string_error_stderr("option --num: invalid int value: %s"),
        ),
        array(
            "invalid_int_trailing_percent",
            3,
            "",
            format_string_error_stderr("option --num: invalid int value: 100%"),
        ),
        array(
            "invalid_long_percent_s",
            3,
            "",
            format_string_error_stderr("option --num: invalid long value: %s"),
        ),
        array(
            "invalid_float_percent_s",
            3,
            "",
            format_string_error_stderr("option --rate: invalid float value: %s"),
        ),
        array(
            "invalid_choice_percent_s",
            3,
            "",
            format_string_error_stderr(
                "option --color: invalid choice: %s (choose from red,blue)"
            ),
        ),
        array(
            "short_attached_invalid_int",
            3,
            "",
            format_string_error_stderr("option -n: invalid int value: %s"),
        ),
        array(
            "callback_value_percent_s",
            3,
            "",
            format_string_error_stderr("rejected %s"),
        ),
        array(
            "missing_argument_option_name_percent_s",
            2,
            "",
            format_string_error_stderr("--%s option takes an argument."),
        ),
        array(
            "unexpected_value_option_name_percent_s",
            2,
            "",
            format_string_error_stderr("--%s option does not take a value."),
        ),
        array(
            "direct_error_percent_s",
            4,
            "",
            format_string_error_stderr("bad %s value"),
        ),
        array(
            "usage_prog_percent_s_on_error",
            1,
            "",
            "Usage: %s [options]\n"
                . format_string_script_name() . ": error: No such option: --bogus\n",
        ),
        array(
            "help_action_description_percent_s",
            0,
            null,
            "",
        ),
        array(
            "version_action_percent_s",
            0,
            "1.2 %s\n\n",
            "",
        ),
        array(
            "version_action_prog_percent_s",
            0,
            "%s\n\n",
            "",
        ),
        array(
            "benign_unknown_option",
            1,
            "",
            format_string_error_stderr("No such option: --bogus"),
        ),
        array(
            "flag_value_not_in_message",
            2,
            "",
            format_string_error_stderr("--flag option does not take a value."),
        ),
    );

    foreach ($cases as $case) {
        list($name, $exit_code, $stdout, $stderr) = $case;
        $failures += format_string_expect_process(
            $name,
            $exit_code,
            $stdout,
            $stderr
        ) ? 0 : 1;
    }

    $stream_cases = array(
        "print_usage_percent_s" => array(
            "Usage: see %s\n",
            function ($stream) {
                $parser = new OptionParser(array("usage" => "see %s"));
                $parser->print_usage($stream);
            },
        ),
        "print_usage_escaped_percent" => array(
            "Usage: 100%%\n",
            function ($stream) {
                $parser = new OptionParser(array("usage" => "100%%"));
                $parser->print_usage($stream);
            },
        ),
        "print_usage_prog_percent_s" => array(
            "Usage: %s [options]\n",
            function ($stream) {
                $parser = new OptionParser(array("prog" => "%s"));
                $parser->print_usage($stream);
            },
        ),
        "print_version_percent_s" => array(
            "1.2 %s\n\n",
            function ($stream) {
                $parser = new OptionParser(array("version" => "1.2 %s"));
                $parser->print_version($stream);
            },
        ),
        "print_version_escaped_percent" => array(
            "100%%\n\n",
            function ($stream) {
                $parser = new OptionParser(array("version" => "100%%"));
                $parser->print_version($stream);
            },
        ),
        "print_version_prog_percent_s" => array(
            "%s\n\n",
            function ($stream) {
                $parser = new OptionParser(array(
                    "version" => "%prog",
                    "prog" => "%s",
                ));
                $parser->print_version($stream);
            },
        ),
    );

    foreach ($stream_cases as $name => $spec) {
        $failures += format_string_expect_stream($name, $spec[0], $spec[1]) ? 0 : 1;
    }

    $help_needles = array(
        "print_help_description_percent_s" => array(
            "hello %s",
            function ($stream) {
                $parser = new OptionParser(array("description" => "hello %s"));
                $parser->print_help($stream);
            },
        ),
        "print_help_description_escaped_percent" => array(
            "100%%",
            function ($stream) {
                $parser = new OptionParser(array("description" => "100%%"));
                $parser->print_help($stream);
            },
        ),
        "print_help_epilog_percent_s" => array(
            "bye %s",
            function ($stream) {
                $parser = new OptionParser(array(
                    "epilog" => "bye %s",
                    "add_help_option" => false,
                ));
                $parser->print_help($stream);
            },
        ),
        "print_help_option_help_percent_s" => array(
            "read %s",
            function ($stream) {
                $parser = new OptionParser(array("add_help_option" => false));
                $parser->add_option(array(
                    "--file",
                    "dest" => "file",
                    "help" => "read %s",
                ));
                $parser->print_help($stream);
            },
        ),
        "print_help_metavar_percent_s" => array(
            "--file=%s",
            function ($stream) {
                $parser = new OptionParser(array("add_help_option" => false));
                $parser->add_option(array(
                    "--file",
                    "dest" => "file",
                    "metavar" => "%s",
                    "help" => "read a file",
                ));
                $parser->print_help($stream);
            },
        ),
    );

    foreach ($help_needles as $name => $spec) {
        $failures += format_string_expect_stream_contains(
            $name,
            $spec[0],
            $spec[1]
        ) ? 0 : 1;
    }

    $failures += format_string_expect_string_value() ? 0 : 1;

    $spawned = count($cases) + count($stream_cases) + count($help_needles) + 1;
    $passed = $spawned - $failures;
    echo "\n$passed passed, $failures failed\n";
    return $failures === 0;
}

function format_string_expect_process($name, $exit_code, $stdout, $stderr) {
    list($got_exit, $got_out, $got_err) = format_string_spawn($name);
    $problems = array();

    if ($got_exit !== $exit_code) {
        $problems[] = "exit code $got_exit, expected $exit_code";
    }
    // null stdout means the help text is formatted, so require the literal
    // description instead of an exact transcript of the whole help page.
    if ($stdout === null) {
        if (strpos($got_out, "hello %s") === false) {
            $problems[] = "stdout is missing the literal description";
        }
    }
    else if ($got_out !== $stdout) {
        $problems[] = "stdout " . format_string_dump($got_out)
            . ", expected " . format_string_dump($stdout);
    }
    if ($got_err !== $stderr) {
        $problems[] = "stderr " . format_string_dump($got_err)
            . ", expected " . format_string_dump($stderr);
    }
    $leak = format_string_format_leak($got_out . $got_err);
    if ($leak !== "") {
        $problems[] = "output contains format-string failure marker '$leak'";
    }

    return format_string_report($name, $problems);
}

function format_string_expect_stream($name, $expected, $invoke) {
    $result = format_string_capture($invoke);
    $problems = array();
    if ($result["threw"] !== "") {
        $problems[] = "threw " . $result["threw"];
    }
    else if ($result["output"] !== $expected) {
        $problems[] = "output " . format_string_dump($result["output"])
            . ", expected " . format_string_dump($expected);
    }
    return format_string_report($name, $problems);
}

function format_string_expect_stream_contains($name, $needle, $invoke) {
    $result = format_string_capture($invoke);
    $problems = array();
    if ($result["threw"] !== "") {
        $problems[] = "threw " . $result["threw"];
    }
    else if (strpos($result["output"], $needle) === false) {
        $problems[] = "output " . format_string_dump($result["output"])
            . " is missing " . format_string_dump($needle);
    }
    $leak = format_string_format_leak($result["output"]);
    if ($leak !== "") {
        $problems[] = "output contains format-string failure marker '$leak'";
    }
    return format_string_report($name, $problems);
}

function format_string_expect_string_value() {
    $problems = array();
    try {
        $parser = new OptionParser();
        $parser->add_option(array("--name", "dest" => "name", "type" => "string"));
        $values = $parser->parse_args(array("prog", "--name", "%s"));
        if (! isset($values->options["name"]) || $values->options["name"] !== "%s") {
            $problems[] = "stored value was not the literal %s";
        }
    }
    catch (Throwable $exception) {
        $problems[] = "threw " . get_class($exception) . ": " . $exception->getMessage();
    }
    return format_string_report("string_value_keeps_percent_s", $problems);
}

function format_string_capture($invoke) {
    $stream = fopen("php://memory", "w+");
    $handler = set_error_handler(function ($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        $invoke($stream);
        rewind($stream);
        $output = stream_get_contents($stream);
        return array("threw" => "", "output" => $output);
    }
    catch (Throwable $exception) {
        return array(
            "threw" => get_class($exception) . ": " . $exception->getMessage()
                . " in " . $exception->getFile() . ":" . $exception->getLine(),
            "output" => "",
        );
    }
    finally {
        if ($handler !== null) {
            set_error_handler($handler);
        }
        else {
            restore_error_handler();
        }
        fclose($stream);
    }
}

function format_string_report($name, $problems) {
    if (empty($problems)) {
        echo "PASS $name\n";
        return true;
    }
    echo "FAIL $name\n";
    foreach ($problems as $problem) {
        echo "  $problem\n";
    }
    return false;
}

function format_string_dump($text) {
    return json_encode($text, JSON_UNESCAPED_SLASHES);
}
