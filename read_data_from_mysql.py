# ==============================
# Read sensor data from MySQL
# Author: Carl
# ==============================

import pymysql

# Database connection configuration
db = pymysql.connect(
    host="localhost",
    user="root",
    password="123456",
    database="sensor_data"
)

# Create cursor object
cursor = db.cursor()

# ---------------- QUERY SECTION ----------------
sql = """
SELECT id, timestamp, temperature, humidity, pressure
FROM readings
ORDER BY id DESC
LIMIT 10;
"""
cursor.execute(sql)
rows = cursor.fetchall()

# ---------------- DISPLAY SECTION ----------------
print("Latest Sensor Readings:")
print("-" * 70)
print(f"{'ID':<5}{'Timestamp':<20}{'Temp(°C)':<10}{'Humidity(%)':<12}{'Pressure(hPa)':<12}")
print("-" * 70)

for row in rows:
    ts = str(row[1])  # Convert datetime to string
    temp = float(row[2])
    hum = float(row[3])
    pres = float(row[4])
    print(f"{row[0]:<5}{ts:<20}{temp:<10.2f}{hum:<12.2f}{pres:<12.2f}")

# Close the database connection
db.close()
