-- =====================================================================
--  College Sports Faculty Portal — Seed Data
--
--  Content matches index.html demo exactly so the PHP pages render
--  an identical UI to the static HTML originals.
-- =====================================================================

USE `csf_portal`;

-- ---------------------------------------------------------------------
-- 1. departments
-- ---------------------------------------------------------------------
INSERT INTO `departments` (`code`,`name`,`full_name`,`icon`,`display_order`) VALUES
    ('engineering',  'Engineering',   'B.E. / B.Tech Programs',                  'bi-cpu',            1),
    ('polytechnic',  'Polytechnic',   'Diploma Programs',                        'bi-tools',          2),
    ('pharmacy',     'YCP(pharmacy)', 'Yashoda College of Pharmacy - B.Pharm Programs', 'bi-capsule',  3),
    ('ytc_pharmacy', 'YTC(pharmacy)', 'YTC(pharmacy) - Pharmacy Programs',       'bi-capsule',        4),
    ('dpharm',       'D.Pharm',       'Diploma in Pharmacy',                     'bi-capsule',        5),
    ('management',   'Management',    'MBA / MCA / BBA / BCA Programs',          'bi-briefcase',      6),
    ('architecture', 'Architecture',  'B.Arch Programs',                         'bi-building',       7);

-- ---------------------------------------------------------------------
-- 2. super-admin
-- ---------------------------------------------------------------------
INSERT INTO `faculty`
    (`username`,`email`,`full_name`,`password_hash`,`role`,`phone`,`is_active`)
VALUES
    ('admin', 'admin@yspm.org', 'Site Administrator',
     '__ADMIN_HASH__', 'SUPER_ADMIN', '+91-9876543210', 1);

-- ---------------------------------------------------------------------
-- 3. faculty users (one per common department)
-- ---------------------------------------------------------------------
INSERT INTO `faculty`
    (`username`,`email`,`full_name`,`password_hash`,`role`,`phone`,`is_active`)
VALUES
    ('eng_faculty',   'eng@yspm.org',   'Dr. Patil (Engineering)',
     '__FACULTY_HASH__', 'FACULTY', '+91-9000000001', 1),
    ('poly_faculty',  'poly@yspm.org',  'Prof. Deshmukh (Polytechnic)',
     '__FACULTY_HASH__', 'FACULTY', '+91-9000000002', 1),
    ('pharm_faculty', 'pharm@yspm.org', 'Dr. Kulkarni (Pharmacy)',
     '__FACULTY_HASH__', 'FACULTY', '+91-9000000003', 1),
    ('dpharm_faculty','dpharm@yspm.org','Dr. Pawar (D.Pharm)',
     '__FACULTY_HASH__', 'FACULTY', '+91-9000000004', 1);

-- ---------------------------------------------------------------------
-- 3b. faculty_departments links for fresh installs
--     (The v3 + v4 migrations also add these for existing installs.
--      This block keeps a fresh install self-contained.)
-- ---------------------------------------------------------------------
INSERT INTO `faculty_departments` (`faculty_id`,`department_id`)
SELECT f.id, d.id
  FROM `faculty` f
  JOIN `departments` d
    ON (f.username = 'eng_faculty'   AND d.code IN ('engineering', 'pharmacy', 'ytc_pharmacy'))
    OR (f.username = 'poly_faculty'  AND d.code IN ('polytechnic', 'dpharm'))
    OR (f.username = 'pharm_faculty' AND d.code IN ('management', 'architecture'));

-- ---------------------------------------------------------------------
-- 4. sample students
-- ---------------------------------------------------------------------
INSERT INTO `students`
    (`enrollment_no`,`full_name`,`dob`,`gender`,`blood_group`,`email`,`mobile`,`parent_phone`,`address`,
     `department_id`,`program`,`academic_year`,`study_year`,`sport_1`,`sport_2`,`achievements`,`created_by`)
VALUES
    ('ENG2025001','Aarav Mehta',     '2005-04-12','Male',  'B+', 'aarav@yspm.edu',  '+91-9800000001','+91-9800000011','Satara, MH',
        1,'B.Tech Computer Engineering','2025-26','Second','Cricket','Football',  'Captain - University XI 2024', 2),
    ('ENG2025002','Priya Joshi',     '2006-01-22','Female','O+', 'priya@yspm.edu',  '+91-9800000002','+91-9800000012','Pune, MH',
        1,'B.Tech IT','2025-26','Second','Badminton','Table Tennis','Silver - State Inter-Univ Badminton', 2),
    ('ENG2025003','Rohan Kadam',     '2004-09-09','Male',  'A+', 'rohan@yspm.edu',  '+91-9800000003','+91-9800000013','Sangli, MH',
        1,'B.Tech Mechanical','2025-26','Third','Athletics','Kabaddi',  'Gold - 100m State Meet', 2),

    ('POL2025001','Sneha Patil',     '2006-07-18','Female','AB+','sneha@yspm.edu',  '+91-9800000004','+91-9800000014','Kolhapur, MH',
        2,'Diploma Computer Engineering','2025-26','First', 'Kabaddi','Kho Kho','Best Defender - Zonal Kabaddi', 3),
    ('POL2025002','Aditya Sawant',   '2005-12-30','Male',  'O-', 'aditya@yspm.edu', '+91-9800000005','+91-9800000015','Satara, MH',
        2,'Diploma Mechanical','2025-26','Second','Football','Athletics', NULL, 3),
    ('POL2025003','Komal Jagtap',    '2004-11-05','Female','B-', 'komal@yspm.edu',  '+91-9800000006','+91-9800000016','Pune, MH',
        2,'Diploma Civil','2025-26','Third', 'Volleyball','Throwball','Captain - Volleyball Team', 3),

    ('PHA2025001','Vikram Shinde',   '2005-03-14','Male',  'A-', 'vikram@yspm.edu', '+91-9800000007','+91-9800000017','Karad, MH',
        3,'B.Pharm','2025-26','Second','Cricket','Chess', NULL, 4),
    ('PHA2025002','Neha Kumbhar',    '2006-08-21','Female','AB-','neha@yspm.edu',   '+91-9800000008','+91-9800000018','Sangli, MH',
        3,'B.Pharm','2025-26','First', 'Table Tennis','Carrom','Bronze - University TT', 4),

    ('MGT2025001','Harsh Ghorpade',  '2004-05-02','Male',  'B+', 'harsh@yspm.edu',  '+91-9800000009','+91-9800000019','Satara, MH',
        6,'BBA Finance','2025-26','Third','Football','Cricket', NULL, 1),
    ('MGT2025002','Pooja Chavan',    '2005-10-17','Female','O+', 'pooja@yspm.edu',  '+91-9800000010','+91-9800000020','Pune, MH',
        6,'BBA Marketing','2025-26','Second','Basketball','Throwball','MVP - Inter-College Basketball', 1);

-- ---------------------------------------------------------------------
-- 5. hero_settings — MATCHES index.html exactly
-- ---------------------------------------------------------------------
INSERT INTO `hero_settings`
    (`id`,`headline`,`subheadline`,`description`,`background_image`,
     `primary_button_text`,`primary_button_link`,
     `secondary_button_text`,`secondary_button_link`)
VALUES
    (1,
     'Excellence in Education,',
     'Grandeur in Sports.',
     'Fostering discipline, teamwork, and athletic excellence across 15+ sporting disciplines at Yashoda Group of Institutions — Engineering, Polytechnic, Pharmacy, MBA, MCA, BBA, BCA & Architecture.',
     'images/hero-bg.jpg',
     'View Latest Notices',   '#notices-section',
     'Our Achievements',      '#achievements-section');

-- ---------------------------------------------------------------------
-- 6. college_settings — MATCHES index.html exactly
-- ---------------------------------------------------------------------
INSERT INTO `college_settings`
    (`id`,`name`,`trust_name`,`affiliation`,`logo_path`,`tagline`,`address`,`phone`,`email`)
VALUES
    (1,
     'YSPM''s Yashoda Technical Campus, Satara',
     'Yashoda Shikshan Prasarak Mandal''s',
     'Approved By AICTE, PCI, New Delhi & Govt. of Maharashtra (DTE Mumbai), Accredited by NAAC and NBA',
     'images/ytc-logo.png',
     'Department of Sports',
     'Satara, Maharashtra, India',
     '+91 02162 234567',
     'sports@ytc.edu.in');

-- ---------------------------------------------------------------------
-- 7. notices — 5 items matching index.html demo
-- ---------------------------------------------------------------------
-- Intentionally empty. Notices are created through the admin portal.

-- ---------------------------------------------------------------------
-- 8. achievements — 3 slides matching index.html carousel
-- ---------------------------------------------------------------------
INSERT INTO `achievements`
    (`student_id`,`title`,`description`,`event_name`,`level`,`position`,`event_date`,`image_path`,`is_published`)
VALUES
    (1, 'State Level Basketball Championship 2025',
     'Won the championship by defeating 12 competing teams from across the state. Outstanding performance in the finals.',
     'State Basketball Championship', 'State', 'Gold',
     '2025-02-15', NULL, 1),
    (2, 'Inter-University Athletics Meet',
     'Secured 2nd place in Women''s 100m Sprint with a timing of 12.4 seconds. Personal best performance.',
     'Inter-University Athletics', 'University', 'Silver',
     '2025-01-28', NULL, 1),
    (3, 'National Badminton Tournament',
     'Mixed doubles team secured bronze at the National Level Inter-College Badminton Championship held in Delhi.',
     'National Badminton Championship', 'National', 'Bronze',
     '2024-12-10', NULL, 1);

-- =====================================================================
--  END OF SEED
-- =====================================================================
