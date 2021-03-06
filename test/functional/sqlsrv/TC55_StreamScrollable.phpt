--TEST--
Streaming Scrollable ResultSets Test
--DESCRIPTION--
Verifies the streaming behavior with scrollable resultsets.
--ENV--
PHPT_EXEC=true
--SKIPIF--
<?php require('skipif_versions_old.inc'); ?>
--FILE--
<?php
require_once('MsCommon.inc');

function streamScroll($noRows, $startRow)
{
    $testName = "Stream - Scrollable";
    startTest($testName);

    setup();
    $tableName = "TC55test";
    if (! isWindows()) {
        $conn1 = AE\connect(array( 'CharacterSet'=>'UTF-8' ));
    } else {
        $conn1 = AE\connect();
    }

    AE\createTestTable($conn1, $tableName);
    AE\insertTestRowsByRange($conn1, $tableName, $startRow, $startRow + $noRows - 1);

    $query = "SELECT * FROM [$tableName] ORDER BY c27_timestamp";
    // Always Encrypted feature does not support SQLSRV_CURSOR_STATIC
    // https://github.com/Microsoft/msphpsql/wiki/Features#aelimitation
    if (AE\isColEncrypted()) {
        $options = array('Scrollable' => SQLSRV_CURSOR_FORWARD);
    } else {
        $options = array('Scrollable' => SQLSRV_CURSOR_STATIC);
    }
    $stmt1 = AE\executeQueryEx($conn1, $query, $options);
    $numFields = sqlsrv_num_fields($stmt1);
    if (AE\isColEncrypted()) {
        $row = $startRow;
        while ($row <= $noRows) {
            if (!sqlsrv_fetch($stmt1, SQLSRV_SCROLL_NEXT)) {
                fatalError("Failed to fetch row ".$row);
            }
            trace("\nStreaming row $row:\n");
            for ($j = 0; $j < $numFields; $j++) {
                $col = $j + 1;
                if (!isUpdatable($col)) {
                    continue;
                }
                if (isStreamable($col)) {
                    verifyStream($stmt1, $startRow + $row - 1, $j);
                }
            }
            $row++;
        }
    } else {
        $row = $noRows;
        while ($row >= 1) {
            if ($row == $noRows) {
                if (!sqlsrv_fetch($stmt1, SQLSRV_SCROLL_LAST)) {
                    fatalError("Failed to fetch row ".$row);
                }
            } else {
                if (!sqlsrv_fetch($stmt1, SQLSRV_SCROLL_PRIOR)) {
                    fatalError("Failed to fetch row ".$row);
                }
            }
            trace("\nStreaming row $row:\n");
            for ($j = 0; $j < $numFields; $j++) {
                $col = $j + 1;
                if (!isUpdatable($col)) {
                    continue;
                }
                if (isStreamable($col)) {
                    verifyStream($stmt1, $startRow + $row - 1, $j);
                }
            }
            $row--;
        }
    }
    sqlsrv_free_stmt($stmt1);

    dropTable($conn1, $tableName);

    sqlsrv_close($conn1);

    endTest($testName);
}

function verifyStream($stmt, $row, $colIndex)
{
    $col = $colIndex + 1;
    if (isStreamable($col)) {
        $type = getSqlType($col);
        if (isBinary($col)) {
            $stream = sqlsrv_get_field($stmt, $colIndex, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY));
        } else {
            if (useUTF8Data()) {
                $stream = sqlsrv_get_field($stmt, $colIndex, SQLSRV_PHPTYPE_STREAM('UTF-8'));
            } else {
                $stream = sqlsrv_get_field($stmt, $colIndex, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_CHAR));
            }
        }
        if ($stream === false) {
            fatalError("Failed to read field $col: $type");
        } else {
            $value = '';
            if ($stream) {
                while (!feof($stream)) {
                    $value .= fread($stream, 8192);
                }
                fclose($stream);
                $data = AE\getInsertData($row, $col);
                if (!checkData($col, $value, $data)) {
                    trace("Data corruption on row $row column $col\n");
                    setUTF8Data(false);
                    die("Data corruption on row $row column $col\n");
                }
            }
            traceData($type, "".strlen($value)." bytes");
        }
    }
}

function checkData($col, $actual, $expected)
{
    $success = true;

    if (isBinary($col)) {
        $actual = bin2hex($actual);
        if (strncasecmp($actual, $expected, strlen($expected)) != 0) {
            $success = false;
        }
    } else {
        if (strncasecmp($actual, $expected, strlen($expected)) != 0) {
            if ($col != 19) {
                // skip ntext
                $pos = strpos($actual, $expected);
                if (($pos === false) || ($pos > 1)) {
                    $success = false;
                }
            }
        }
    }
    if (!$success) {
        trace("\nData error\nExpected:\n$expected\nActual:\n$actual\n");
    }

    return ($success);
}

if (! isWindows()) {
    setUTF8Data(true);
}
try {
    streamScroll(20, 1);
} catch (Exception $e) {
    echo $e->getMessage();
}
setUTF8Data(false);

?>
--EXPECT--
Test "Stream - Scrollable" completed successfully.
