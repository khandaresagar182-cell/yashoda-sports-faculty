-- =====================================================================
--  Migration v43 — One unified A–Z game catalogue for every department.
--
--  Background: v22 seeded dept_game_catalog for polytechnic + dpharm,
--  v23 added a 9-game list for engineering/pharmacy/ytc_pharmacy, v24
--  added a 16-game list for management/architecture. Per client, the
--  picker (student wizard Step 3, faculty profile, provisional/final
--  Game dropdowns) should now offer ONE shared list of 42 games, sorted
--  alphabetically, identical across all 7 departments.
--
--  This migration merges every game_code that already existed with 20
--  new sports (Yoga, Gymnastics, Judo, Fencing, Mallakhamb, Rope
--  Mallakhamb, Archery, Shooting, Lawn Tennis, Power Lifting, Weight
--  Lifting, Body Building, Rugby, Rowing, Cross Country, Cycling, Track
--  Cycling, Canoeing & Kayaking, Wushu, Karate) and writes the full set
--  to all departments with display_order = A–Z rank.
--
--  Nothing is removed — carrom / 4 x 100 m Relay (previously poly/dpharm
--  only) are kept so existing student_selected_games rows never orphan.
--
--  Idempotent: ON DUPLICATE KEY UPDATE refreshes display_name /
--  display_order / is_active for rows that already exist; re-running is
--  a no-op once the catalogue matches.
-- =====================================================================

USE `csf_portal`;

INSERT INTO `dept_game_catalog`
    (`department_id`, `game_code`, `display_name`, `max_picks`, `display_order`, `is_active`)
SELECT d.id, g.game_code, g.display_name, 4, g.display_order, 1
  FROM `departments` d
  CROSS JOIN (
            SELECT 'relay_4x100m'      AS game_code, '4 x 100 m Relay'      AS display_name,  1 AS display_order
  UNION ALL SELECT 'archery',              'Archery',                2
  UNION ALL SELECT 'athletics',            'Athletics',              3
  UNION ALL SELECT 'badminton',            'Badminton',              4
  UNION ALL SELECT 'baseball',             'Baseball',               5
  UNION ALL SELECT 'basketball',           'Basketball',             6
  UNION ALL SELECT 'body_building',        'Body Building',           7
  UNION ALL SELECT 'boxing',               'Boxing',                 8
  UNION ALL SELECT 'canoeing_kayaking',    'Canoeing & Kayaking',    9
  UNION ALL SELECT 'carrom',               'Carrom',                10
  UNION ALL SELECT 'chess',                'Chess',                 11
  UNION ALL SELECT 'cricket',              'Cricket',               12
  UNION ALL SELECT 'cross_country',        'Cross Country',         13
  UNION ALL SELECT 'cycling',              'Cycling',               14
  UNION ALL SELECT 'fencing',              'Fencing',               15
  UNION ALL SELECT 'football',             'Football',              16
  UNION ALL SELECT 'gymnastics',           'Gymnastics',            17
  UNION ALL SELECT 'handball',             'Handball',              18
  UNION ALL SELECT 'hockey',               'Hockey',                19
  UNION ALL SELECT 'judo',                 'Judo',                  20
  UNION ALL SELECT 'kabaddi',              'Kabaddi',               21
  UNION ALL SELECT 'karate',               'Karate',                22
  UNION ALL SELECT 'kho_kho',              'Kho Kho',               23
  UNION ALL SELECT 'lawn_tennis',          'Lawn Tennis',           24
  UNION ALL SELECT 'mallakhamb',           'Mallakhamb',            25
  UNION ALL SELECT 'netball',              'Netball',               26
  UNION ALL SELECT 'power_lifting',        'Power Lifting',         27
  UNION ALL SELECT 'rope_mallakhamb',      'Rope Mallakhamb',       28
  UNION ALL SELECT 'rowing',               'Rowing',                29
  UNION ALL SELECT 'rugby',                'Rugby',                 30
  UNION ALL SELECT 'shooting',             'Shooting',              31
  UNION ALL SELECT 'shooting_ball',        'Shooting Ball',         32
  UNION ALL SELECT 'softball',             'Softball',              33
  UNION ALL SELECT 'swimming',             'Swimming',              34
  UNION ALL SELECT 'table_tennis',         'Table Tennis',          35
  UNION ALL SELECT 'taekwondo',            'Taekwondo',             36
  UNION ALL SELECT 'track_cycling',        'Track Cycling',         37
  UNION ALL SELECT 'volleyball',           'Volleyball',            38
  UNION ALL SELECT 'weight_lifting',       'Weight Lifting',        39
  UNION ALL SELECT 'wrestling',            'Wrestling (FS & GR)',   40
  UNION ALL SELECT 'wushu',                'Wushu',                 41
  UNION ALL SELECT 'yoga',                 'Yoga',                  42
  ) g
 WHERE d.code IN ('engineering','polytechnic','pharmacy','architecture','dpharm','ytc_pharmacy','management')
ON DUPLICATE KEY UPDATE
    `display_name`  = VALUES(`display_name`),
    `display_order` = VALUES(`display_order`),
    `is_active`     = 1;

-- =====================================================================
--  END OF MIGRATION v43
-- =====================================================================
