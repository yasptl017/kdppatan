# Database Migration Guide - K.D. Polytechnic Website

## Overview

This project uses a custom migration system based on SQL files instead of Laravel migrations. All database schema changes are tracked in the `/db` folder with version-controlled SQL files.

## Migration Files

All migration files are stored in: `f:\Laravel\kdppatan\db\`

### File Naming Convention
- Pattern: `migration_XXX_description.sql`
- Example: `migration_001_create_dept_timetable.sql`
- **XXX**: Sequential version number (001, 002, 003, etc.)
- **description**: Brief description of what the migration does

## How to Apply Migrations

### Option 1: Manual Import (phpMyAdmin)
1. Open phpMyAdmin
2. Select your database `kdpweb`
3. Go to **Import** tab
4. Click **Choose File** and select the migration SQL file
5. Click **Import**

### Option 2: Command Line (MySQL)
```bash
mysql -u root -p kdpweb < migration_001_create_dept_timetable.sql
```

### Option 3: Auto-Apply (PHP Script)
The admin management pages use `CREATE TABLE IF NOT EXISTS` statements that auto-create tables on first use.
- This happens automatically when you first visit the management page
- Example: When you visit `/Admin/manage_dept_timetable.php`, the table is created automatically

## Recent Migration Applied

### Migration: Create Department Time Tables
**File**: `migration_001_create_dept_timetable.sql`

**Table**: `dept_timetable`

**Fields**:
- `id` - Primary key (auto-increment)
- `department` - Department name
- `title` - Time table title/name
- `display_order` - Sort order (0 = highest priority)
- `file_path` - Path to uploaded file
- `created_at` - Timestamp of creation
- `updated_at` - Timestamp of last update

**SQL**:
```sql
CREATE TABLE IF NOT EXISTS `dept_timetable` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `file_path` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `department` (`department`),
  KEY `display_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

## For Future Database Changes

When you need to add new features that require database changes:

1. **Create a new migration file** with the next version number:
   - Example: `migration_002_add_column_to_table.sql`

2. **Write the SQL** with proper:
   - Comments explaining the change
   - Error handling (IF NOT EXISTS, IF EXISTS)
   - Indexes for performance

3. **Test it** before applying to production:
   - Run on development database first
   - Verify all features work

4. **Keep migration files** in the repository for history and documentation

## Advantages of This Approach

✅ No manual database updates needed
✅ Changes are version-controlled in the db folder
✅ Easy to track what database changes were made
✅ Can be reviewed by team members before applying
✅ Simple to understand SQL without framework complexity

## Files Modified/Created for Time Table Feature

1. **Migration**: `db/migration_001_create_dept_timetable.sql`
2. **Admin Management**: `Admin/manage_dept_timetable.php`
3. **Department Frontend**: `Applied/timetable.php`
4. **Navigation Config**: `Applied/dptnavigation.php` (updated)
5. **Admin Sidebar**: `Admin/sidebar.php` (updated)

## How the Time Table Feature Works

### For Departments (Frontend)
- Page: `Applied/timetable.php`
- Shows all uploaded time tables for a department
- Includes search functionality to filter by title
- Downloads/views the uploaded file
- Tab only appears if department has time tables uploaded

### For Admin (Backend)
- Management Page: `Admin/manage_dept_timetable.php`
- Add new time tables with:
  - Department selection
  - Title/Name
  - Display order (sorting)
  - File upload (PDF, DOC, DOCX, images, Excel)
- Edit existing entries
- Delete entries
- Search and filter functionality with DataTables

### Navigation Order (Department Pages)
1. About
2. Notice Board
3. Academic Calendar
4. **Time Tables** ← NEW
5. Faculty
6. Activities
7. Facilities
8. Newsletter
9. Syllabus
10. Placement (if data exists)

## Next Steps

1. ✅ Apply the migration by opening `manage_dept_timetable.php` in the admin panel
2. ✅ Upload time tables for departments
3. ✅ Departments will automatically show the "Time Tables" tab
4. ✅ Search and view time tables from department pages
