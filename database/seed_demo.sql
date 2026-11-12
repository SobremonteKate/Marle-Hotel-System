-- Marle Hotel — demo reference data (rooms, amenities, packages, site options).
-- No guest records, bookings, reviews, chats or staff accounts are included.

INSERT INTO `amenitiestbl` VALUES (1,'Spa Treatment','Unwind with a relaxing spa treatment arranged during your stay.'),(2,'Infinity Pool Access','Enjoy refreshing access to the infinity pool during your stay.'),(3,'In-Room Dining','Enjoy a prepared meal delivered conveniently to your room.'),(4,'Breakfast Package','Start your day with a convenient breakfast prepared by Marle Hotel.');
INSERT INTO `packagetbl` VALUES (1,'Celebration Package 1','Offer one\r\nOffer two'),(2,'Celebration Package 2','Offer one\r\nOffer two\r\nOffer three'),(3,'Celebration Package 3','Offer one\r\nOffer two\r\nOffer three');
INSERT INTO `roomstbl` VALUES (1,'Family','2',200.00,6,'',30,0,NULL),(2,'Suite','1',200.00,4,'',30,0,NULL),(3,'With Garage','3',150.00,3,'',30,1,'Plumbing repair, for 1 day'),(4,'Without Garage','4',150.00,2,'',30,0,NULL),(7,'Without Garage','5',150.00,2,'',30,0,NULL),(8,'Without Garage','6',150.00,2,'',30,0,NULL),(9,'Without Garage','7',150.00,2,'',30,0,NULL),(10,'With Garage','8',150.00,3,'',30,0,NULL),(11,'With Garage','9',150.00,3,'',30,0,NULL),(12,'With Garage','10',150.00,3,'',30,0,NULL),(13,'Suite','11',200.00,4,'',30,0,NULL),(14,'Suite','12',200.00,4,'',30,0,NULL),(15,'Family','13',200.00,6,'',30,0,NULL),(16,'Family','14',200.00,6,'',30,0,NULL),(17,'Family','15',200.00,6,'',30,1,'Plumbing repair'),(18,'Family','16',200.00,6,'',30,0,NULL);
INSERT INTO `site_options` VALUES ('deposit_bank_details','Marle Hotel & Restaurant — BDO Savings (ask the front desk for the account number)'),('deposit_gcash_number','0917 000 0000'),('deposit_hold_hours','24'),('deposit_rate','50'),('last_archive_sweep','1787676750'),('last_chat_sweep','1792255420');

-- ---------------------------------------------------------------------------
-- Demo staff accounts  (password for all three: Marle@2026)
-- Change or delete these before exposing the app to the internet.
-- ---------------------------------------------------------------------------
INSERT INTO `userstbl` (`username`, `password`, `usertype`, `status`, `failed_attempts`, `picture`) VALUES
('admin',      '$2y$10$j.yqv5hifFtMFmggSY51LuYQAowdX6C1g1Yd7kJ2an9eGLLfmQuLm', 'admin',      'active', 0, ''),
('emp',        '$2y$10$t1qv263QjQpmKpbh9a1fx.d5fx6GtfMzKzfLoZFORLaeh2cR.K1p6', 'employee',   'active', 0, ''),
('superadmin', '$2y$10$nmqQCy/W1MH8xfRJ33O9I.DC35qQXnR4aUNxXeDHfUW/J.ZuwQKma', 'superadmin', 'active', 0, '');
