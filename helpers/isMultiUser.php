<?php
// helpers.php

/**
 * Set hotels.is_multi_user = 1 if there is at least one active non-owner user for that hotel, else 0.
 * Uses users.is_active = 1 as active.
 */
function updateHotelIsMultiUser(PDO $conn, int $hotelId) {
    try {
        $countSql = "SELECT COUNT(1) AS cnt 
                     FROM users 
                     WHERE hotel_id = :hotel_id 
                       AND is_active = 1
                       AND (user_role IS NULL OR user_role != 'owner')";
        $cstmt = $conn->prepare($countSql);
        $cstmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $cstmt->execute();
        $row = $cstmt->fetch(PDO::FETCH_ASSOC);
        $cnt = (int)($row['cnt'] ?? 0);

        $flag = $cnt > 0 ? 1 : 0;
        $upd = $conn->prepare("UPDATE hotels SET is_multi_user = :flag, updated_at = NOW() WHERE hotel_id = :hotel_id");
        $upd->bindValue(':flag', $flag, PDO::PARAM_INT);
        $upd->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $upd->execute();
        return true;
    } catch (PDOException $e) {
        error_log("updateHotelIsMultiUser error: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetch a single hotel row with settings (optional) and cast is_multi_user to boolean.
 * Returns associative array or null.
 */
function fetchHotelRow(PDO $conn, int $hotelId) {
    $stmt = $conn->prepare("SELECT * FROM hotels WHERE hotel_id = :hotel_id LIMIT 1");
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmt->execute();
    $hotel = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hotel) return null;

    // cast is_multi_user to boolean for cleaner frontend usage
    $hotel['is_multi_user'] = (isset($hotel['is_multi_user']) && (int)$hotel['is_multi_user'] === 1) ? true : false;

    // include settings row if you like (optional)
    $fs = $conn->prepare("SELECT * FROM settings WHERE hotel_id = :hotel_id LIMIT 1");
    $fs->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $fs->execute();
    $hotel['settings'] = $fs->fetch(PDO::FETCH_ASSOC) ?: null;

    // ensure categories key exists for frontend compatibility
    $hotel['categories'] = $hotel['categories'] ?? [];

    return $hotel;
}

?>