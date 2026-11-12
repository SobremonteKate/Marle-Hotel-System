-- MarleHotel Phase 10: replace placeholder amenity catalog copy.
-- Preserve amenity IDs so existing references remain valid.

UPDATE amenitiestbl
SET title = 'Spa Treatment',
    description = 'Unwind with a relaxing spa treatment arranged during your stay.'
WHERE id = 1;

UPDATE amenitiestbl
SET title = 'Infinity Pool Access',
    description = 'Enjoy refreshing access to the infinity pool during your stay.'
WHERE id = 2;

UPDATE amenitiestbl
SET title = 'In-Room Dining',
    description = 'Enjoy a prepared meal delivered conveniently to your room.'
WHERE id = 3;

UPDATE amenitiestbl
SET title = 'Breakfast Package',
    description = 'Start your day with a convenient breakfast prepared by Marle Hotel.'
WHERE id = 4;
