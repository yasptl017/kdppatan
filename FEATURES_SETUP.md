# Time Tables & Results Features - Complete Setup Guide

## ✅ Features Implemented

### 1. **Time Tables Feature** 📚
- **Admin Page**: `Admin/manage_dept_timetable.php`
- **Frontend**: `Applied/timetable.php`
- **Table**: `dept_timetable`
- **Migration**: `db/migration_001_create_dept_timetable.sql`

### 2. **Results Feature** 📄
- **Admin Page**: `Admin/manage_dept_results.php`
- **Frontend**: `Applied/results.php`
- **Table**: `dept_results`
- **Migration**: `db/migration_002_create_dept_results.sql`

---

## 🚀 Quick Start - Apply Migrations

### **Option 1: Automatic Migration Runner (EASIEST)** ✅

1. Open in browser: `http://localhost/kdppatan/migrate.php`
2. The script will automatically:
   - Create `dept_timetable` table
   - Create `dept_results` table
   - Display success/error messages

### **Option 2: phpMyAdmin Manual Import**

For each migration file:
1. Go to: `http://localhost/phpmyadmin`
2. Select database: `kdpweb`
3. Click **Import** tab
4. Browse and select:
   - `db/migration_001_create_dept_timetable.sql`
   - `db/migration_002_create_dept_results.sql`
5. Click Import

---

## 📋 New Department Navigation Sequence

```
1. About
2. Notice Board
3. Academic Calendar
4. Time Tables ← NEW
5. Results ← NEW
6. Faculty
7. Activities
8. Facilities
9. Newsletter
10. Syllabus
11. Placement
```

---

## 💼 For Department Managers / Admins

### **Add Time Tables:**
1. Login to admin panel
2. Go to: **Departments → Time Tables**
3. Click **"Add Time Table"**
4. Fill in:
   - **Department**: Select department
   - **Title**: e.g., "Semester 5 Timetable"
   - **Display Order**: 0 (first), 1 (second), etc.
   - **File**: Upload PDF, Excel, Image, etc.
5. Click **Save**

### **Add Results:**
1. Login to admin panel
2. Go to: **Departments → Results**
3. Click **"Add Result"**
4. Fill in:
   - **Department**: Select department
   - **Title**: e.g., "Semester 5 Results"
   - **Display Order**: 0 (first), 1 (second), etc.
   - **File**: Upload PDF, Excel, Image, etc.
5. Click **Save**

**Features:**
- ✅ Edit existing entries
- ✅ Delete entries
- ✅ Search & filter with DataTables
- ✅ Multiple files per department

---

## 👥 For Students / Department Visitors

### **View Time Tables:**
1. Go to any department page
2. Click **"Time Tables"** tab (appears if data exists)
3. Browse or search by title
4. Click **"Download / View"** to access file

### **View Results:**
1. Go to any department page
2. Click **"Results"** tab (appears if data exists)
3. Browse or search by title
4. Click **"Download / View"** to access file

**Features:**
- ✅ Real-time search
- ✅ Responsive grid layout
- ✅ Direct download/view links
- ✅ Mobile-friendly

---

## 📁 Files Created/Modified

### **Created (New Files):**
- ✅ `db/migration_001_create_dept_timetable.sql`
- ✅ `db/migration_002_create_dept_results.sql`
- ✅ `Admin/manage_dept_timetable.php`
- ✅ `Admin/manage_dept_results.php`
- ✅ `Applied/timetable.php`
- ✅ `Applied/results.php`

### **Modified (Updated Files):**
- ✅ `Applied/dptnavigation.php` - Added tabs for Time Tables & Results
- ✅ `Admin/sidebar.php` - Added menu links

### **Migration Tool:**
- ✅ `migrate.php` - Automated migration runner

---

## 🗄️ Database Structure

### **dept_timetable Table:**
```sql
┌─────────────┬─────────────────┬──────────────┐
│ Field       │ Type            │ Purpose      │
├─────────────┼─────────────────┼──────────────┤
│ id          │ int(11) AUTO    │ Primary key  │
│ department  │ varchar(255)    │ Dept name    │
│ title       │ varchar(255)    │ Title        │
│ display_order │ int(11)       │ Sort order   │
│ file_path   │ varchar(255)    │ File path    │
│ created_at  │ timestamp       │ Created date │
│ updated_at  │ timestamp       │ Updated date │
└─────────────┴─────────────────┴──────────────┘
```

### **dept_results Table:**
```sql
(Same structure as dept_timetable)
```

---

## ✨ Key Features

### **Admin Side:**
- ✅ Add/Edit/Delete multiple entries
- ✅ Department-specific management
- ✅ Display order sorting (0 = highest priority)
- ✅ File upload (PDF, DOC, DOCX, Images, Excel)
- ✅ Search & filter with DataTables
- ✅ Role-based access (Admin or Department Head)

### **Student Side:**
- ✅ Browse by department
- ✅ Real-time search filtering
- ✅ Responsive grid layout
- ✅ One-click download/view
- ✅ Auto-hide tabs if no data
- ✅ Mobile-friendly

---

## 🔧 Technical Details

### **Migration Approach:**
- Uses SQL migration files (not Laravel)
- Simpler, no framework dependencies
- Version-controlled in `/db` folder
- Auto-creates tables with `CREATE TABLE IF NOT EXISTS`
- Named: `migration_XXX_description.sql`

### **Conditional Display:**
- Time Tables tab only shows if department has records
- Results tab only shows if department has records
- Same logic as existing Placement & Academic Calendar features

### **File Upload:**
- Supported formats: PDF, DOC, DOCX, JPG, JPEG, PNG, GIF, WEBP, XLSX, XLS
- Upload directory: `Admin/uploads/dept_timetable/` and `Admin/uploads/dept_results/`
- Auto-deletes old files when updated

---

## 🧪 Testing Checklist

- [ ] Run migrations (visit `http://localhost/kdppatan/migrate.php`)
- [ ] Verify tables created in database
- [ ] Admin can add time table entry
- [ ] Admin can add results entry
- [ ] Time Tables tab appears on department page
- [ ] Results tab appears on department page
- [ ] Can search/filter entries
- [ ] Can download/view files
- [ ] Can edit entries
- [ ] Can delete entries
- [ ] Tabs hidden when no data
- [ ] Works on mobile

---

## 🎯 Admin Menu Structure

**Departments submenu now includes:**
```
├── About Department
├── Notice Board
├── Academic Calendar
├── Time Tables ← NEW
├── Results ← NEW
├── Add Faculty
├── Add Activities
├── Add Facilities
├── Add Newsletter
├── Add Syllabus
└── Placement
```

---

## 🚨 Important Notes

1. **Migration is Required**: Tables must be created before using features
2. **Auto-Create Fallback**: Tables auto-create on first admin page visit (CREATE TABLE IF NOT EXISTS)
3. **File Storage**: Uploaded files stored in `Admin/uploads/` subdirectories
4. **Search Function**: Client-side real-time search (no database query per keystroke)
5. **Display Order**: Lower numbers appear first (0 = first, 1 = second, etc.)

---

## 📞 Support

If something doesn't work:
1. Check that migrations were applied (visit `migrate.php`)
2. Verify tables exist in database
3. Check file upload permissions in `Admin/uploads/` folder
4. Clear browser cache
5. Check PHP error logs

---

**Status**: ✅ Ready to Use - Just run migrations first!

For migration details, see `MIGRATION_GUIDE.md`
