<?php
// Simple test runner for parse_address_with_commas without requiring PHPUnit.
require_once __DIR__ . '/../_inc/usps-address-sanitizer.php';

// CSV format: address,line_1,line_2,city,state,zip
// line_2 may be empty. If line_1 equals the literal string "EXCEPTION" we'll expect the parser
// to throw an Exception for that row.

function compare_field($rownum, $name, $expected, $actual, $address, $result)
{
    if ($expected == $actual) {
        return true;
    }
    echo "Row {$rownum}: FAIL {$name}\n";
    echo "  Expected: {$expected}\n";
    echo "    Actual: {$actual}\n";
    echo "    Address: {$address}\n";
    echo "    Full result: " . print_r($result, true) . "\n";
    return false;
}

$csv_file = __DIR__ . '/parse-address-cases.csv';
if (!file_exists($csv_file)) {
    echo "ERROR: test data file not found: {$csv_file}\n";
    exit(2);
}

$fh = fopen($csv_file, 'r');
if ($fh === false) {
    echo "ERROR: unable to open {$csv_file}\n";
    exit(2);
}

$header = fgetcsv($fh);
if ($header === false) {
    echo "ERROR: empty CSV file\n";
    exit(2);
}

$rownum = 1; // header is row 1
$failures = 0;
echo "Running parse_address_with_commas tests from {$csv_file}...\n";
while (($row = fgetcsv($fh)) !== false) {
    $rownum++;
    // Expect at least 6 columns: address, line_1, line_2, city, state, zip
    if (count($row) < 6) {
        echo "Row {$rownum}: SKIP - not enough columns\n";
        $failures++;
        continue;
    }

    list($address, $exp_line_1, $exp_line_2, $exp_city, $exp_state, $exp_zip) = $row;

    $expect_exception = ($exp_line_1 === 'EXCEPTION');

    try {
        $result = parse_address_with_commas($address);
        if ($expect_exception) {
            echo "Row {$rownum}: FAIL - expected exception but parser returned a result\n";
            $failures++;
            continue;
        }

        $ok = true;
        if (!compare_field($rownum, 'line_1', $exp_line_1, $result['line_1'], $address, $result)) {
            $ok = false;
        }
        if (!compare_field($rownum, 'line_2', $exp_line_2, $result['line_2'], $address, $result)) {
            $ok = false;
        }
        if (!compare_field($rownum, 'city', $exp_city, $result['city'], $address,  $result)) {
            $ok = false;
        }
        if (!compare_field($rownum, 'state', $exp_state, $result['state'], $address, $result)) {
            $ok = false;
        }
        if (!compare_field($rownum, 'zip', $exp_zip, $result['zip'], $address, $result)) {
            $ok = false;
        }

        if ($ok) {
            echo "Row {$rownum}: PASS\n";
        } else {
            $failures++;
        }

    } catch (Exception $e) {
        if ($expect_exception) {
            echo "Row {$rownum}: PASS (exception thrown as expected)\n";
        } else {
            echo "Row {$rownum}: FAIL - unexpected exception: " . $e->getMessage() . "\n";
            $failures++;
        }
    }
}

fclose($fh);

if ($failures === 0) {
    echo "All tests passed.\n";
    exit(0);
} else {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}
