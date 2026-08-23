-- =====================================================================
--  Seed data - run AFTER schema.sql
--  Default admin login ->  phone: 9999999999   password: Admin@123
--  !! CHANGE THE PASSWORD IMMEDIATELY AFTER FIRST LOGIN !!
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO `users` (`id`, `parent_id`, `role`, `name`, `phone`, `email`, `password_hash`, `agency_name`, `is_active`)
VALUES (1, NULL, 'admin', 'Head Office Admin', '9999999999', 'admin@example.com',
        '$2y$12$6YksVKpG1shlmhhPsluzh.e8gxL4QiAfUugViqlHjGEOm012G4DL.', 'Head Office', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `lead_sources` (`name`) VALUES
  ('Walk-in'), ('Reference'), ('Facebook'), ('Instagram'), ('WhatsApp'),
  ('Website'), ('Job Portal'), ('Newspaper Ad'), ('Partner / Sub-agent'), ('Other')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `job_categories` (`name`) VALUES
  ('Nurse / Healthcare'), ('Driver - Light'), ('Driver - Heavy'), ('Construction Labour'),
  ('Electrician'), ('Plumber'), ('Welder'), ('AC Technician'), ('Carpenter'),
  ('Mason'), ('Cook / Chef'), ('Housekeeping'), ('Security Guard'), ('Salesman / Retail'),
  ('Store Keeper'), ('Accountant'), ('Civil Engineer'), ('Mechanical Engineer'),
  ('IT / Software'), ('Hotel & Hospitality'), ('Beautician'), ('Tailor'), ('Other')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Overseas recruitment document checklist
INSERT INTO `document_types` (`name`, `code`, `applies_to`, `is_required`, `has_expiry`, `sort_order`) VALUES
  ('Filled Application Form', 'APPLICATION_FORM', 'both',    1, 0,  1),
  ('Passport - First Page',   'PASSPORT_FRONT',   'project', 1, 1,  2),
  ('Passport - Last Page',    'PASSPORT_BACK',    'project', 1, 1,  3),
  ('Passport Size Photo',     'PHOTO',            'both',    1, 0,  4),
  ('Resume / CV',             'RESUME',           'both',    1, 0,  5),
  ('Educational Certificate', 'EDU_CERT',         'project', 1, 0,  6),
  ('Experience Certificate',  'EXP_CERT',         'project', 0, 0,  7),
  ('Aadhaar Card',            'AADHAAR',          'project', 1, 0,  8),
  ('PAN Card',                'PAN',              'project', 0, 0,  9),
  ('Medical Fitness Report',  'MEDICAL',          'project', 1, 1, 10),
  ('Police Clearance (PCC)',  'PCC',              'project', 1, 1, 11),
  ('Offer Letter',            'OFFER_LETTER',     'project', 0, 0, 12),
  ('Employment Contract',     'CONTRACT',         'project', 0, 0, 13),
  ('Visa Copy',               'VISA',             'project', 0, 1, 14),
  ('Emigration Clearance',    'EMIGRATION',       'project', 0, 0, 15),
  ('Air Ticket',              'TICKET',           'project', 0, 0, 16),
  ('Service Agreement',       'AGREEMENT',        'project', 1, 0, 17),
  ('Payment Receipt',         'PAYMENT_RECEIPT',  'project', 0, 0, 18),
  ('Other Document',          'OTHER',            'both',    0, 0, 99)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `settings` (`key_name`, `value`) VALUES
  ('agency_name',              'My Recruitment Agency'),
  ('project_code_prefix',      'PRJ'),
  ('max_upload_mb',            '15'),
  ('call_popup_min_duration',  '0'),
  ('followup_reminder_minutes','15'),
  ('partner_can_convert',      '1'),
  ('partner_can_see_all_docs', '0')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
