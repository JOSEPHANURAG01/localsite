<?php
// subscribe.php
$config = include('files/config.php');

$servername = $config['servername'];
$username = $config['username'];
$password = $config['password'];
$dbname = $config['dbname'];

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => "Connection failed: " . $conn->connect_error]);
    exit;
}

header('Content-Type: application/json');

$response = ['status' => ''];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST['email']) || empty($_POST['email'])) {
        $response['status'] = 'error';
        $response['message'] = "Email is required."; // More user-friendly message
        echo json_encode($response);
        exit; // Stop execution after sending the error
    }

    $email = $_POST['email'];

    $stmt = $conn->prepare("INSERT INTO Subsriptions (Email) VALUES (?)");
    if ($stmt === false) {
        $response['status'] = 'error';
        $response['message'] = "Prepare failed: " . $conn->error;
        echo json_encode($response);
        exit;
    }
    $stmt->bind_param("s", $email);

    if ($stmt->execute()) {
        $response['status'] = 'success';
        $response['message'] = "You have successfully subscribed!";
        
    } else {
        $response['status'] = 'error';
        if ($conn->errno == 1062) {
            $response['message'] = "This email is already subscribed.";
        } else {
            $response['message'] = "Error: " . $stmt->error;
        }
    }

    $stmt->close();
} else {
    $response['status'] = 'error';
    $response['message'] = "Invalid request method.";
}

$conn->close();

echo json_encode($response);
?>