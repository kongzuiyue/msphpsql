--TEST--
Test data classification feature - retrieving sensitivity metadata if supported
--DESCRIPTION--
If both ODBC and server support this feature, this test verifies that sensitivity metadata can be added and correctly retrieved. If not, it will at least test the new statement attribute and some error cases.
--ENV--
PHPT_EXEC=true
--SKIPIF--
<?php require('skipif_versions_old.inc'); ?>
--FILE--
<?php
function testErrorCases($conn, $tableName, $isSupported)
{
    // This function will check two error cases:
    // (1) if supported, the query should return a column with no classification
    $options = array('DataClassification' => true);
    $tsql = ($isSupported)? "SELECT PatientId FROM $tableName" : "SELECT * FROM $tableName";
    $stmt = sqlsrv_query($conn, $tsql, array(), $options);
    if (!$stmt) {
        fatalError("testErrorCases (1): failed with sqlsrv_query '$tsql'.\n");
    }

    $notAvailableErr = '*Failed to retrieve Data Classification Sensitivity Metadata. If the driver and the server both support the Data Classification feature, check whether the query returns columns with classification information.';

    $metadata = sqlsrv_field_metadata($stmt);
    if ($metadata) {
        echo "testErrorCases (1): expected sqlsrv_field_metadata to fail\n";
    }

    if (!fnmatch($notAvailableErr, sqlsrv_errors()[0]['message'])) {
        var_dump(sqlsrv_errors());
    }

    // (2) call sqlsrv_prepare() with DataClassification but do not execute the stmt
    $stmt = sqlsrv_prepare($conn, $tsql, array(), $options);
    if (!$stmt) {
        fatalError("testErrorCases (2): failed with sqlsrv_prepare '$tsql'.\n");
    }

    $executeFirstErr = '*The statement must be executed to retrieve Data Classification Sensitivity Metadata.';
    $metadata = sqlsrv_field_metadata($stmt);
    if ($metadata) {
        echo "testErrorCases (2): expected sqlsrv_field_metadata to fail\n";
    }

    if (!fnmatch($executeFirstErr, sqlsrv_errors()[0]['message'])) {
        var_dump(sqlsrv_errors());
    }
}

function isDataClassSupported($conn)
{
    // Check both SQL Server version and ODBC driver version
    $msodbcsqlVer = sqlsrv_client_info($conn)['DriverVer'];
    $version = explode(".", $msodbcsqlVer);

    // ODBC Driver must be 17.2 or above
    if ($version[0] < 17 || $version[1] < 2) {
        return false;
    }

    // SQL Server must be SQL Server 2019 or above
    $serverVer = sqlsrv_server_info($conn)['SQLServerVersion'];
    if (explode('.', $serverVer)[0] < 15) {
        return false;
    }

    return true;
}

function getRegularMetadata($conn, $tsql)
{
    // Run the query without data classification metadata
    $stmt1 = sqlsrv_query($conn, $tsql);
    if (!$stmt1) {
        fatalError("getRegularMetadata (1): failed in sqlsrv_query.\n");
    }

    // Run the query with the attribute set to false
    $options = array('DataClassification' => false);
    $stmt2 = sqlsrv_query($conn, $tsql, array(), $options);
    if (!$stmt2) {
        fatalError("getRegularMetadata (2): failed in sqlsrv_query.\n");
    }

    // The metadata for each statement, column by column, should be identical
    $numCol = sqlsrv_num_fields($stmt1);
    $metadata1 = sqlsrv_field_metadata($stmt1);
    $metadata2 = sqlsrv_field_metadata($stmt2);

    for ($i = 0; $i < $numCol; $i++) {
        $diff = array_diff($metadata1[$i], $metadata2[$i]);
        if (!empty($diff)) {
            print_r($diff);
        }
    }

    return $stmt1;
}

function verifyClassInfo($input, $actual)
{
    // For simplicity of this test, only one set of sensitivity data. Namely,
    // an array with one set of Label (name, id) and Information Type (name, id)
    if (count($actual) != 1) {
        echo "Expected an array with only one element\n";
        return false;
    }

    if (count($actual[0]) != 2) {
        echo "Expected a Label pair and Information Type pair\n";
        return false;
    }

    // Label should be name and id pair (id should be empty)
    if (count($actual[0]['Label']) != 2) {
        echo "Expected only two elements for the label\n";
        return false;
    }
    $label = $input[0];
    if ($actual[0]['Label']['name'] !== $label || !empty($actual[0]['Label']['id'])){
        return false;
    }

    // Like Label, Information Type should also be name and id pair (id should be empty)
    if (count($actual[0]['Information Type']) != 2) {
        echo "Expected only two elements for the information type\n";
        return false;
    }
    $info = $input[1];
    if ($actual[0]['Information Type']['name'] !== $info || !empty($actual[0]['Information Type']['id'])){
        return false;
    }

    return true;
}

function compareDataClassification($stmt1, $stmt2, $classData)
{
    $numCol = sqlsrv_num_fields($stmt1);

    $metadata1 = sqlsrv_field_metadata($stmt1);
    $metadata2 = sqlsrv_field_metadata($stmt2);

    // The built-in array_diff_assoc() function compares the keys and values
    // of two (or more) arrays, and returns an array that contains the entries
    // from array1 that are not present in array2 or array3, etc.
    //
    // For this test, $metadata2 should have one extra key 'Data Classification',
    // which should not be present in $metadata1
    //
    // If the column does not have sensitivity metadata, the value should be an
    // empty array. Otherwise, it should contain an array with one set of
    // Label (name, id) and Information Type (name, id)

    $noClassInfo = array('Data Classification' => array());
    for ($i = 0; $i < $numCol; $i++) {
        $diff = array_diff_assoc($metadata2[$i], $metadata1[$i]);

        // Is classification input data empty?
        if (empty($classData[$i])) {
            // Then it should be equivalent to $noClassInfo
            if ($diff !== $noClassInfo) {
                var_dump($diff);
            }
        } else {
            // Verify the classification metadata
            if (!verifyClassInfo($classData[$i], $diff['Data Classification'])) {
                var_dump($diff);
            }
        }
    }
}

///////////////////////////////////////////////////////////////////////////////////////
require_once('MsCommon.inc');

$conn = AE\connect();
if (!$conn) {
    fatalError("Failed to connect.\n");
}

$isSupported = isDataClassSupported($conn);

// Create a test table
$tableName = 'srvPatients';
$colMeta = array(new AE\ColumnMeta('INT', 'PatientId', 'IDENTITY NOT NULL'),
                 new AE\ColumnMeta('CHAR(11)', 'SSN'),
                 new AE\ColumnMeta('NVARCHAR(50)', 'FirstName'),
                 new AE\ColumnMeta('NVARCHAR(50)', 'LastName'),
                 new AE\ColumnMeta('DATE', 'BirthDate'));
AE\createTable($conn, $tableName, $colMeta);

// If data classification is supported, then add sensitivity classification metadata
// to columns SSN and Birthdate
$classData = [
                array(),
                array('Highly Confidential - GDPR', 'Credentials'),
                array(),
                array(),
                array('Confidential Personal Data', 'Birthdays')
             ];

if ($isSupported) {
    // column SSN
    $label = $classData[1][0];
    $infoType = $classData[1][1];
    $sql = "ADD SENSITIVITY CLASSIFICATION TO [$tableName].SSN WITH (LABEL = '$label', INFORMATION_TYPE = '$infoType')";
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) {
        fatalError("SSN: Add sensitivity $label and $infoType failed.\n");
    }

    // column BirthDate
    $label = $classData[4][0];
    $infoType = $classData[4][1];
    $sql = "ADD SENSITIVITY CLASSIFICATION TO [$tableName].BirthDate WITH (LABEL = '$label', INFORMATION_TYPE = '$infoType')";
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) {
        fatalError("BirthDate: Add sensitivity $label and $infoType failed.\n");
    }
}

testErrorCases($conn, $tableName, $isSupported);

// Run the query without data classification metadata
$tsql = "SELECT * FROM $tableName";
$stmt = getRegularMetadata($conn, $tsql);

// Proceeed to retrieve sensitivity metadata, if supported
if ($isSupported) {
    $options = array('DataClassification' => true);
    $stmt1 = sqlsrv_prepare($conn, $tsql, array(), $options);
    if (!$stmt1) {
        fatalError("Error when calling sqlsrv_prepare '$tsql'.\n");
    }
    if (!sqlsrv_execute($stmt1)) {
        fatalError("Error in executing statement.\n");
    }

    compareDataClassification($stmt, $stmt1, $classData);
    sqlsrv_free_stmt($stmt1);

    // $stmt2 should produce the same result as the previous $stmt1
    $stmt2 = sqlsrv_query($conn, $tsql, array(), $options);
    if (!$stmt2) {
        fatalError("Error when calling sqlsrv_query '$tsql'.\n");
    }

    compareDataClassification($stmt, $stmt2, $classData);
    sqlsrv_free_stmt($stmt2);
}

sqlsrv_free_stmt($stmt);

dropTable($conn, $tableName);
sqlsrv_close($conn);

echo "Done\n";
?>
--EXPECT--
Done