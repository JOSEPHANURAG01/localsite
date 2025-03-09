<?php
// Include the database configuration
$config = include('files/config.php');

$servername = $config['servername'];
$username = $config['username'];
$password = $config['password'];
$dbname = $config['dbname'];

// Establish the database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check the database connection
if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => "Connection failed: " . $conn->connect_error]);
    exit;
}

header('Content-Type: application/json');

// Initialize the response
$response = ['status' => ''];

// Handle the POST request
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate required fields
    $requiredFields = ['firstName', 'lastName', 'email', 'address', 'zipCode'];
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            $response['status'] = 'error';
            $response['message'] = ucfirst($field) . " is required.";
            echo json_encode($response);
            exit;
        }
    }

    // Collect form data
    $firstName = trim($_POST['firstName']);
    $lastName = trim($_POST['lastName']);
    $name2 = isset($_POST['name2']) ? trim($_POST['name2']) : null;
    $email = trim($_POST['email']);
    $section = trim($_POST['section']);
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
    $address = trim($_POST['address']);
    $unit = isset($_POST['unit']) ? trim($_POST['unit']) : null;
    $zipCode = trim($_POST['zipCode']);
    $residenceType = trim($_POST['residenceType']);
    $householdMembers = isset($_POST['householdMembers']) ? (int)$_POST['householdMembers'] : null;
    $newsletter = isset($_POST['newsletter']) ? 1 : 0;
    $events = isset($_POST['events']) ? 1 : 0;

    // Check if email already exists
    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM ResidentDirectory WHERE email = ?");
    if ($checkStmt === false) {
        $response['status'] = 'error';
        $response['message'] = "Check failed: " . $conn->error;
        echo json_encode($response);
        exit;
    }

    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $checkStmt->bind_result($count);
    $checkStmt->fetch();
    $checkStmt->close();

    if ($count > 0) {
        $response['status'] = 'error';
        $response['message'] = "User already registered";
        echo json_encode($response);
        exit;
    }

    // Prepare the SQL statement
    $stmt = $conn->prepare(
        "INSERT INTO ResidentDirectory 
        (Section,Name1,Name2, Surname, email, Phone_No, Address1, Address2, Street, owner_renter, HouseholdMembers, newsletter, events) 
        VALUES (?,?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if ($stmt === false) {
        $response['status'] = 'error';
        $response['message'] = "Prepare failed: " . $conn->error;
        echo json_encode($response);
        exit;
    }

    // Bind parameters
    $stmt->bind_param(
        "sssssssssssii",
        $section,
        $firstName, 
        $name2,
        $lastName, 
        $email, 
        $phone, 
        $address, 
        $unit, 
        $zipCode, 
        $residenceType, 
        $householdMembers, 
        $newsletter, 
        $events
    );

    // Execute the query
    if ($stmt->execute()) {
        $response['status'] = 'success';
        $response['message'] = "Registration successful!";
    } else {
        $response['status'] = 'error';
        $response['message'] = "Error: " . $stmt->error;
    }

    // Close the statement
    $stmt->close();
} else {
    $response['status'] = 'error';
    $response['message'] = "Invalid request method.";
}

// Close the connection
$conn->close();

// Return the JSON response
echo json_encode($response);
?>