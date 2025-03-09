<?php
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: admin.html");
    exit();
}

$loginusername = $_SESSION['username'];
$config = include('files/config.php');
$conn = new mysqli($config['servername'], $config['username'], $config['password'], $config['dbname']);

if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Handle messages
$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Handle email removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_email'])) {
    $emailToRemove = trim($_POST['remove_email']);
    if (!empty($emailToRemove)) {
        $stmt = $conn->prepare("DELETE FROM ResidentDirectory WHERE Email LIKE ?");
        $emailToRemove = "%" . $emailToRemove . "%";
        $stmt->bind_param("s", $emailToRemove);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Email '$emailToRemove' was successfully removed!";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
        $stmt->close();
    }
}

// Handle resident deletion (without email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_resident'])) {
    $name = trim($_POST['name']);
    $surname = trim($_POST['surname']);
    $address = trim($_POST['address']);
    $street = trim($_POST['street']);

    $stmt = $conn->prepare("DELETE FROM ResidentDirectory WHERE Name1 = ? AND Surname = ? AND Address1 = ? AND Street = ? AND (Email IS NULL OR Email = '')");
    $stmt->bind_param("ssss", $name, $surname, $address, $street);
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Resident $name $surname was successfully removed!";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $stmt->close();
}

// Handle information updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_resident'])) {
    if (isset($_POST['old_email'])) {
        // Update for residents with emails
        $oldEmail = trim($_POST['old_email']);
        $newEmail = trim($_POST['email']);
        $name = trim($_POST['name']);
        $surname = trim($_POST['surname']);
        $address = trim($_POST['address']);
        $street = trim($_POST['street']);

        // Validate new email format
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error_message'] = "Invalid email format";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        // Check if new email already exists (except current one)
        $stmt = $conn->prepare("SELECT * FROM ResidentDirectory WHERE Email LIKE ? AND Email NOT LIKE ?");
        $searchPattern = "%" . $newEmail . "%";
        $currentPattern = "%" . $oldEmail . "%";
        $stmt->bind_param("ss", $searchPattern, $currentPattern);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $_SESSION['error_message'] = "Email already exists";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        $stmt = $conn->prepare("UPDATE ResidentDirectory SET 
            Name1 = ?,
            Surname = ?,
            Address1 = ?,
            Street = ?,
            Email = REPLACE(Email, ?, ?)
            WHERE Email LIKE ?");
        
        $searchPattern = "%" . $oldEmail . "%";
        $stmt->bind_param("sssssss", $name, $surname, $address, $street, $oldEmail, $newEmail, $searchPattern);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Resident information updated successfully!";
        } else {
            $_SESSION['error_message'] = "Error updating resident: " . $conn->error;
        }
        $stmt->close();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        // Update for residents without emails (with possible email addition)
        $oldName = trim($_POST['old_name']);
        $oldSurname = trim($_POST['old_surname']);
        $oldAddress = trim($_POST['old_address']);
        $oldStreet = trim($_POST['old_street']);
        
        $newName = trim($_POST['name']);
        $newSurname = trim($_POST['surname']);
        $newAddress = trim($_POST['address']);
        $newStreet = trim($_POST['street']);
        $newEmail = strtolower(trim($_POST['email']));

        // Validate email format if provided
        if (!empty($newEmail) && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error_message'] = "Invalid email format";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        // Check email uniqueness if provided
        if (!empty($newEmail)) {
            $stmt = $conn->prepare("SELECT * FROM ResidentDirectory 
                                  WHERE Email REGEXP ?");
            $regex = '[[:<:]]' . $conn->real_escape_string($newEmail) . '[[:>:]]';
            $stmt->bind_param("s", $regex);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $_SESSION['error_message'] = "Email already exists";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
            $stmt->close();
        }

        // Build dynamic query
        $sql = "UPDATE ResidentDirectory SET 
            Name1 = ?,
            Surname = ?,
            Address1 = ?,
            Street = ?";
        
        $params = [$newName, $newSurname, $newAddress, $newStreet];
        $types = "ssss";
        
        if (!empty($newEmail)) {
            $sql .= ", Email = ?";
            $params[] = $newEmail;
            $types .= "s";
        }
        
        $sql .= " WHERE COALESCE(Name1, '') = ?
            AND COALESCE(Surname, '') = ?
            AND COALESCE(Address1, '') = ?
            AND COALESCE(Street, '') = ?";
        
        $params = array_merge($params, [$oldName, $oldSurname, $oldAddress, $oldStreet]);
        $types .= "ssss";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Resident information updated successfully!";
        } else {
            $_SESSION['error_message'] = "Error updating resident: " . $conn->error;
        }
        $stmt->close();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Fetch residents with emails
$sqlWithEmail = "SELECT Name1, Surname, Address1, Street, Email FROM ResidentDirectory WHERE Email IS NOT NULL AND Email != ''";
$resultWith = $conn->query($sqlWithEmail);

$residentsWithEmails = [];
if ($resultWith->num_rows > 0) {
    while ($row = $resultWith->fetch_assoc()) {
        $emails = preg_split('/\s+/', $row['Email']);
        foreach ($emails as $email) {
            if (!empty(trim($email))) {
                $residentsWithEmails[] = [
                    'name'    => $row['Name1'],
                    'surname' => $row['Surname'],
                    'address' => $row['Address1'],
                    'street'  => $row['Street'],
                    'email'   => trim($email)
                ];
            }
        }
    }
}

// Fetch residents without emails
$sqlWithoutEmail = "SELECT Name1, Surname, Address1, Street FROM ResidentDirectory WHERE Email IS NULL OR Email = ''";
$resultWithout = $conn->query($sqlWithoutEmail);

$residentsWithoutEmails = [];
if ($resultWithout->num_rows > 0) {
    while ($row = $resultWithout->fetch_assoc()) {
        $residentsWithoutEmails[] = [
            'name'    => $row['Name1'],
            'surname' => $row['Surname'],
            'address' => $row['Address1'],
            'street'  => $row['Street']
        ];
    }
}

$conn->close();
$gmailURL = "https://mail.google.com/mail/?view=cm&fs=1&to=" . urlencode(implode(", ", array_column($residentsWithEmails, 'email')));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Directory</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Previous CSS styles remain the same */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f7fa;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 15px 0;
            border-bottom: 3px solid #3498db;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f7fa;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 15px 0;
            border-bottom: 3px solid #3498db;
        }

        h2 {
            color: #2c3e50;
            margin: 0;
            padding: 0;
        }

        .tabs {
            margin-bottom: 25px;
            border-bottom: 2px solid #ecf0f1;
        }

        .tab-link {
            padding: 12px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            color: #7f8c8d;
            transition: all 0.3s ease;
            margin-right: 5px;
            border-radius: 6px 6px 0 0;
        }

        .tab-link.active {
            color: #3498db;
            background: #f8f9fa;
            font-weight: 600;
            border-bottom: 3px solid #3498db;
        }

        .tab-link:hover {
            background: #f8f9fa;
            color: #3498db;
        }

        .directory-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 25px 0;
            overflow: hidden;
            border-radius: 8px;
        }

        .directory-table thead tr {
            background: linear-gradient(145deg, #3498db, #2980b9);
            color: white;
        }

        .directory-table th {
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
        }

        .directory-table td {
            padding: 12px 20px;
            border-bottom: 1px solid #ecf0f1;
        }

        .directory-table tbody tr {
            transition: background-color 0.2s ease;
        }

        .directory-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .directory-table tbody tr:hover {
            background-color: #f1f5f9;
        }

        .action-btn {
            padding: 6px 15px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: 5px;
        }

        .edit-btn {
            background: #3498db;
        }

        .save-btn {
            background: #2ecc71;
        }

        .edit-btn:hover {
            background: #2980b9;
        }

        .save-btn:hover {
            background: #27ae60;
        }

        .action-btn:hover {
            background: #c0392b;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .email-all-btn {
            display: inline-flex;
            align-items: center;
            padding: 12px 25px;
            background: linear-gradient(145deg, #2ecc71, #27ae60);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin-bottom: 20px;
            transition: all 0.2s ease;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .email-all-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.15);
        }

        .logout-btn {
            display: inline-block;
            padding: 10px 25px;
            background: #95a5a6;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .logout-btn:hover {
            background: #7f8c8d;
            transform: translateY(-1px);
        }

        .no-residents {
            color: #7f8c8d;
            padding: 20px;
            text-align: center;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 20px 0;
        }

        .search-container {
            margin-bottom: 20px;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 12px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 25px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #ffffff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .search-input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 2px 8px rgba(52, 152, 219, 0.2);
        }

        .edit-field {
            padding: 5px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
        }

        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
       

    </style>
</head>
<body>
    <div class="header-container">
        <h2>Welcome, <?= htmlspecialchars($loginusername) ?></h2>
        <div class="logout-section">
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="tabs">
        <button class="tab-link active" onclick="openTab(event, 'with-emails')">Residents with Emails</button>
        <button class="tab-link" onclick="openTab(event, 'without-emails')">Residents without Emails</button>
    </div>

    <div class="container">
        <div id="with-emails" class="tab-content active">
            <div class="search-container">
                <input type="text" class="search-input" placeholder="Search residents with emails..." onkeyup="filterTable(this, 'with-emails')">
            </div>
            <a href="<?= $gmailURL ?>" target="_blank" class="email-all-btn">
                Email All Residents
            </a>

            <?php if (!empty($residentsWithEmails)): ?>
            <table class="directory-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Surname</th>
                        <th>Address</th>
                        <th>Street</th>
                        <th>Email</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($residentsWithEmails as $resident): ?>
                    <tr>
                        <form method="POST">
                            <td>
                                <span class="view-mode"><?= htmlspecialchars($resident['name']) ?></span>
                                <input type="text" class="edit-field edit-mode" name="name" 
                                    value="<?= htmlspecialchars($resident['name']) ?>" style="display: none;">
                            </td>
                            <td>
                                <span class="view-mode"><?= htmlspecialchars($resident['surname']) ?></span>
                                <input type="text" class="edit-field edit-mode" name="surname" 
                                    value="<?= htmlspecialchars($resident['surname']) ?>" style="display: none;">
                            </td>
                            <td>
                                <span class="view-mode"><?= htmlspecialchars($resident['address']) ?></span>
                                <input type="text" class="edit-field edit-mode" name="address" 
                                    value="<?= htmlspecialchars($resident['address']) ?>" style="display: none;">
                            </td>
                            <td>
                                <span class="view-mode"><?= htmlspecialchars($resident['street']) ?></span>
                                <input type="text" class="edit-field edit-mode" name="street" 
                                    value="<?= htmlspecialchars($resident['street']) ?>" style="display: none;">
                            </td>
                            <td>
                                <span class="view-mode"><?= htmlspecialchars($resident['email']) ?></span>
                                <input type="email" class="edit-field edit-mode" name="email" 
                                    value="<?= htmlspecialchars($resident['email']) ?>" style="display: none;">
                            </td>
                            <td>
                                <input type="hidden" name="old_email" value="<?= htmlspecialchars($resident['email']) ?>">
                                <button type="button" class="edit-btn action-btn" onclick="toggleEdit(this)">Edit</button>
                                <button type="submit" class="save-btn action-btn" name="update_resident" style="display: none;">Save</button>
                                <button type="submit" class="action-btn" name="remove_email" value="<?= htmlspecialchars($resident['email']) ?>">Remove</button>
                            </td>
                        </form>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="no-residents">No residents with emails found.</p>
            <?php endif; ?>
        </div>

        <div id="without-emails" class="tab-content">
            <div class="search-container">
                <input type="text" class="search-input" placeholder="Search residents without emails..." onkeyup="filterTable(this, 'without-emails')">
            </div>
            <?php if (!empty($residentsWithoutEmails)): ?>
            <table class="directory-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Surname</th>
                        <th>Address</th>
                        <th>Street</th>
                        <th>Email</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($residentsWithoutEmails as $resident): ?>
                    <tr>
                        <form method="POST">
                            <td>
                                <span class="view-mode"><?= htmlspecialchars($resident['name']) ?></span>
                                <input type="text" class="edit-field edit-mode" name="name" 
                                    value="<?= htmlspecialchars($resident['name']) ?>" style="display: none;">
                            </td>
                            <td>
                                <span class="view-mode"><?= htmlspecialchars($resident['surname']) ?></span>
                                <input type="text" class="edit-field edit-mode" name="surname" 
                                    value="<?= htmlspecialchars($resident['surname']) ?>" style="display: none;">
                            </td>
                            <td>
                                <span class="view-mode"><?= htmlspecialchars($resident['address']) ?></span>
                                <input type="text" class="edit-field edit-mode" name="address" 
                                    value="<?= htmlspecialchars($resident['address']) ?>" style="display: none;">
                            </td>
                            <td>
                                <span class="view-mode"><?= htmlspecialchars($resident['street']) ?></span>
                                <input type="text" class="edit-field edit-mode" name="street" 
                                    value="<?= htmlspecialchars($resident['street']) ?>" style="display: none;">
                            </td>
                            <td>
                                <span class="view-mode">No email</span>
                                <input type="email" class="edit-field edit-mode" name="email" 
                                    placeholder="Add email" style="display: none;">
                            </td>
                            <td>
                                <input type="hidden" name="old_name" value="<?= htmlspecialchars($resident['name']) ?>">
                                <input type="hidden" name="old_surname" value="<?= htmlspecialchars($resident['surname']) ?>">
                                <input type="hidden" name="old_address" value="<?= htmlspecialchars($resident['address']) ?>">
                                <input type="hidden" name="old_street" value="<?= htmlspecialchars($resident['street']) ?>">
                                <button type="button" class="edit-btn action-btn" onclick="toggleEdit(this)">Edit</button>
                                <button type="submit" class="save-btn action-btn" name="update_resident" style="display: none;">Save</button>
                                <button type="submit" class="action-btn" name="remove_resident">Delete</button>
                            </td>
                        </form>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="no-residents">No residents without emails found.</p>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function openTab(evt, tabName) {
            const tabContents = document.getElementsByClassName("tab-content");
            const tabLinks = document.getElementsByClassName("tab-link");
            
            Array.from(tabContents).forEach(tab => {
                tab.style.display = 'none';
                tab.classList.remove('active');
            });
            
            Array.from(tabLinks).forEach(link => {
                link.classList.remove('active');
            });
            
            const selectedTab = document.getElementById(tabName);
            selectedTab.style.display = 'block';
            selectedTab.classList.add('active');
            evt.currentTarget.classList.add('active');

            const searchInputs = document.querySelectorAll('.search-input');
            searchInputs.forEach(input => input.value = '');
            filterTable({value: ''}, tabName);
        }

        function filterTable(input, tabName) {
            const filter = input.value.toUpperCase();
            const table = document.getElementById(tabName).getElementsByClassName('directory-table')[0];
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                const tds = tr[i].getElementsByTagName('td');
                let match = false;
                
                for (let j = 0; j < tds.length - 1; j++) {
                    const td = tds[j];
                    if (td) {
                        const txtValue = td.textContent || td.innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            match = true;
                            break;
                        }
                    }
                }
                
                tr[i].style.display = match ? "" : "none";
            }
        }

        function toggleEdit(button) {
            const row = button.closest('tr');
            const viewElements = row.querySelectorAll('.view-mode');
            const editElements = row.querySelectorAll('.edit-mode');
            const saveButton = row.querySelector('.save-btn');
            const editButton = row.querySelector('.edit-btn');

            viewElements.forEach(el => el.style.display = 
                el.style.display === 'none' ? 'inline' : 'none');
            editElements.forEach(el => el.style.display = 
                el.style.display === 'none' ? 'inline-block' : 'none');
            saveButton.style.display = saveButton.style.display === 'none' ? 'inline-block' : 'none';
            editButton.style.display = editButton.style.display === 'none' ? 'inline-block' : 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tab-content');
            tabs.forEach((tab, index) => {
                if(index !== 0) tab.style.display = 'none';
            });
            document.querySelector('.tab-link').classList.add('active');
        });

        <?php if (!empty($success_message)): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '<?= addslashes($success_message) ?>',
            timer: 3000,
            timerProgressBar: true,
            showConfirmButton: false
        });
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: '<?= addslashes($error_message) ?>',
            timer: 3000,
            timerProgressBar: true,
            showConfirmButton: false
        });
        <?php endif; ?>
    </script>
</body>
</html>