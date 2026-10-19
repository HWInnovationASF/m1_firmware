<?php
include '../con_db.php';

$sql = "SELECT * FROM m_realtime ORDER BY DT DESC LIMIT 1";
$result = $conn->query($sql);
$res = $result->fetch_assoc();

// print_r($res);
echo json_encode($res);

?>