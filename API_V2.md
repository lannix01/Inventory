# Inventory Module - New Features & API Documentation

## Overview
The Inventory module has been significantly enhanced with the following new features:
- ✅ Tools/Profile Management
- ✅ Device Management
- ✅ 2FA/MFA Setup
- ✅ Departments Management
- ✅ Reports & Analytics
- ✅ Notifications System
- ✅ Import/Export Functionality

## 🔧 Tools Endpoints

### Get Profile
```
GET /api/inventory/v1/auth/profile
Authorization: Bearer {token}

Response:
{
  "profile": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "phone_no": "254712345678",
    "inventory_role": "admin",
    "department_id": 1,
    "last_login_at": "2026-04-16T09:08:14Z",
    "two_factor_enabled": false
  }
}
```

### Update Profile
```
PATCH /api/inventory/v1/auth/profile
Authorization: Bearer {token}
Content-Type: application/json

Body:
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "phone_no": "254712345679"
}

Response: Updated profile object
```

### Change Password
```
POST /api/inventory/v1/auth/password
Authorization: Bearer {token}
Content-Type: application/json

Body:
{
  "current_password": "oldpassword123",
  "new_password": "newpassword123",
  "new_password_confirmation": "newpassword123"
}

Response: { "message": "Password changed successfully." }
```

### List Devices
```
GET /api/inventory/v1/auth/devices
Authorization: Bearer {token}

Response:
{
  "devices": [
    {
      "id": 1,
      "name": "iPhone 12",
      "device_id": "uuid-123",
      "device_platform": "ios",
      "last_used_at": "2026-04-16T09:08:14Z",
      "last_ip": "192.168.1.1",
      "expires_at": "2026-05-16T09:08:14Z",
      "is_current": true
    }
  ],
  "total": 1
}
```

### Revoke Device
```
DELETE /api/inventory/v1/auth/devices/{deviceId}
Authorization: Bearer {token}

Response: { "message":  "Device revoked successfully." }
```

## 🔐 2FA Endpoints

### Enable 2FA
```
POST /api/inventory/v1/auth/2fa/enable
Authorization: Bearer {token}

Response:
{
  "secret": "ABCDEFGHIJKLMNOP",
  "qr_code_url": "https://chart.googleapis.com/chart?...",
  "backup_codes": ["ABC123", "DEF456", "GHI789", "JKL012", "MNO345", "PQR678", "STU901", "VWX234"]
}
```

### Verify 2FA
```
POST /api/inventory/v1/auth/2fa/verify
Authorization: Bearer {token}
Content-Type: application/json

Body:
{
  "code": "123456"
}

Response:
{
  "backup_codes": ["ABC123", "DEF456", "GHI789", "JKL012", "MNO345", "PQR678", "STU901", "VWX234"],
  "message": "2FA enabled successfully. Save your backup codes in a safe place."
}
```

### Disable 2FA
```
POST /api/inventory/v1/auth/2fa/disable
Authorization: Bearer {token}
Content-Type: application/json

Body:
{
  "password": "yourpassword123"
}

Response: { "message": "2FA disabled successfully." }
```

## 🏢 Departments Endpoints

### List Departments
```
GET /api/inventory/v1/departments?q=search&page=1
Authorization: Bearer {token}

Response:
{
  "departments": [
    {
      "id": 1,
      "name": "IT Department",
      "code": "IT",
      "created_at": "2026-01-15T10:30:00Z",
      "updated_at": "2026-01-15T10:30:00Z"
    }
  ],
  "pagination": { "total": 10, "per_page": 20, "current_page": 1 }
}
```

### Create Department
```
POST /api/inventory/v1/departments
Authorization: Bearer {token}
Content-Type: application/json

Body:
{
  "name": "HR Department",
  "code": "HR"
}

Response: { "department": {...}, "message": "Department created." }
```

### Get Department
```
GET /api/inventory/v1/departments/{id}
Authorization: Bearer {token}

Response:
{
  "department": {...},
  "user_count": 5
}
```

### Update Department
```
PATCH /api/inventory/v1/departments/{id}
Authorization: Bearer {token}
Content-Type: application/json

Body:
{
  "name": "Updated Department Name"
}
```

### Delete Department
```
DELETE /api/inventory/v1/departments/{id}
Authorization: Bearer {token}

Note: Cannot delete if it has active users
```

## 📊 Reports Endpoints

### Inventory Value Report
```
GET /api/inventory/v1/reports/inventory-value
Authorization: Bearer {token}

Response:
{
  "items": [
    {
      "id": 1,
      "name": "Router Model X",
      "sku": "ROUTER001",
      "quantity": 50,
      "unit_cost": 1500,
      "total_value": 75000,
      "reorder_level": 10,
      "is_low_stock": false,
      "group": "Routers"
    }
  ],
  "summary": {
    "total_items": 150,
    "total_value": 500000,
    "low_stock_count": 8,
    "average_item_value": 3333.33
  }
}
```

### Technician Report
```
GET /api/inventory/v1/reports/technician-stats?from_date=2026-01-01&to_date=2026-12-31
Authorization: Bearer {token}

Response:
{
  "technicians": [
    {
      "id": 1,
      "name": "Tech Name",
      "email": "tech@example.com",
      "assignments_count": 50,
      "items_allocated": 200,
      "items_deployed": 150
    }
  ],
  "period": {
    "from": "2026-01-01",
    "to": "2026-12-31"
  }
}
```

### Movement Summary
```
GET /api/inventory/v1/reports/movements?from_date=2026-01-01&movement_type=transfer
Authorization: Bearer {token}

Response:
{
  "movements": [...],
  "summary": {
    "total_movements": 100,
    "by_type": {
      "transfer": 60,
      "return": 30,
      "deploy": 10
    }
  }
}
```

### Audit Report
```
GET /api/inventory/v1/reports/audit-log?user_id=1&action=login&from_date=2026-01-01
Authorization: Bearer {token}

Response: { "activities": [...] }
```

### Low Stock Trend
```
GET /api/inventory/v1/reports/low-stock-trend?days=30
Authorization: Bearer {token}

Response: { "low_stock_items": [...], "total_count": 5 }
```

## 🔔 Notifications Endpoints

### List Notifications
```
GET /api/inventory/v1/notifications?type=low_stock&unread_only=true&page=1
Authorization: Bearer {token}

Response:
{
  "notifications": [
    {
      "id": 1,
      "title": "Low Stock Alert",
      "message": "Router X has dropped below reorder level",
      "type": "low_stock",
      "source_type": "item",
      "source_id": 5,
      "metadata": { "item_id": 5, "current_qty": 5, "reorder_level": 10 },
      "read": false,
      "read_at": null,
      "created_at": "2026-04-16T09:08:14Z"
    }
  ],
  "unread_count": 3,
  "pagination": {...}
}
```

### Get Unread Count
```
GET /api/inventory/v1/notifications/unread-count
Authorization: Bearer {token}

Response: { "unread_count": 3 }
```

### Mark As Read
```
POST /api/inventory/v1/notifications/{id}/read
Authorization: Bearer {token}

Response: { "notification": {...}, "message": "Notification marked as read." }
```

### Mark All As Read
```
POST /api/inventory/v1/notifications/read-all
Authorization: Bearer {token}

Response: { "marked_count": 3 }
```

### Delete Notification
```
DELETE /api/inventory/v1/notifications/{id}
Authorization: Bearer {token}

Response: { "message": "Notification deleted." }
```

## 📤 Import/Export Endpoints

### Export Items
```
GET /api/inventory/v1/export/items
Authorization: Bearer {token}

Returns: CSV file with headers:
SKU, Name, Description, Item Group, Unit Cost, Quantity, Reorder Level, Status
```

### Import Items
```
POST /api/inventory/v1/import/items
Authorization: Bearer {token}
Content-Type: multipart/form-data

Form Data:
- file: <CSV file>

Response:
{
  "imported": 45,
  "failed": 2,
  "errors": [
    {
      "row": 10,
      "error": "SKU already exists"
    }
  ]
}
```

### Export Receipts
```
GET /api/inventory/v1/export/receipts
Authorization: Bearer {token}

Returns: CSV file export of all receipts
```

### Export Groups
```
GET /api/inventory/v1/export/groups
Authorization: Bearer {token}

Returns: CSV file export of all item groups
```

### Get Import Template
```
GET /api/inventory/v1/import/template?type=items
Authorization: Bearer {token}

Response:
{
  "template": {
    "columns": {
      "SKU": "string, (required, unique)",
      "Name": "string, (required)",
      "Description": "string",
      "Item Group": "string (will be created if not exists)",
      "Unit Cost": "float",
      "Quantity": "integer",
      "Reorder Level": "integer",
      "Status": "Active|Inactive"
    },
    "example": [
      ["ROUTER001", "TR-001 Router", "Entry level router", "Routers", "1500.00", "50", "10", "Active"]
    ]
  },
  "type": "items",
  "encoding": "UTF-8",
  "delimiter": ","
}
```

## 🚀 Usage Examples

### Example: Complete 2FA Setup Flow
```bash
# 1. Enable 2FA
curl -X POST https://api.example.com/api/inventory/v1/auth/2fa/enable \
  -H "Authorization: Bearer {token}" \
  | jq '.data.secret'

# 2. Scan QR code with authenticator app
# 3. Get 6-digit code from app
# 4. Verify code
curl -X POST https://api.example.com/api/inventory/v1/auth/2fa/verify \
  -H "Authorization: Bearer {token}" \
  -d '{"code": "123456"}'

# 5. Save backup codes in secure location
```

### Example: Import Items
```bash
# 1. Get template
curl https://api.example.com/api/inventory/v1/import/template?type=items

# 2. Create CSV file according to template
# 3. Upload file
curl -X POST https://api.example.com/api/inventory/v1/import/items \
  -H "Authorization: Bearer {token}" \
  -F "file=@items.csv"

# Response shows import results
```

## 📝 Notes
- All endpoints require inventory.db middleware (handles database switching)
- Authentication required via Bearer token (inventory.api.auth middleware)
- Rate limiting applied: 120 requests/minute for API, 20/minute for login
- CSV imports support UTF-8 encoding, comma delimiter, quoted fields
- 2FA uses TOTP standard (compatible with Google Authenticator, Authy, Microsoft Authenticator)
- Notifications are stored in database for persistence and audit trail

## 🔧 Troubleshooting
- **"Target class not found" error**: Clear cache with `php artisan route:clear`
- **CSV import fails**: Verify UTF-8 encoding and delimiter
- **2FA verification fails**: Check system time synchronization (TOTP is time-sensitive)
- **Permission denied on delete**: Check for active users in department before deletion
