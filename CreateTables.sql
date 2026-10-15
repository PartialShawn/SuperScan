SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Table structure for table `baseline`
--

CREATE TABLE `baseline` (
  `file_path` varchar(200) NOT NULL,
  `file_hash` char(40) NOT NULL,
  `file_last_mod` char(19) DEFAULT NULL,
  `acct` varchar(20) NOT NULL DEFAULT 'Not specified'
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- --------------------------------------------------------

--
-- Table structure for table `history`
--

CREATE TABLE `history` (
  `stamp` char(19) DEFAULT NULL,
  `status` varchar(10) NOT NULL,
  `file_path` varchar(200) NOT NULL,
  `hash_org` varchar(40) DEFAULT NULL,
  `hash_new` varchar(40) DEFAULT NULL,
  `file_last_mod` char(19) DEFAULT NULL,
  `acct` varchar(20) NOT NULL
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- --------------------------------------------------------

--
-- Table structure for table `scanned`
--

CREATE TABLE `scanned` (
  `scanned` char(19) NOT NULL,
  `changes` int(11) NOT NULL DEFAULT 0,
  `acct` varchar(20) NOT NULL
) ENGINE=Aria DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

COMMIT;


