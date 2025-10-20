# ===========================
# Raspberry Pi Sensor Logger
# Author: Carl
# Description:
# This script reads temperature, humidity, and pressure data
# from the Sense HAT, compensates for CPU heat interference,
# and stores the readings into a MySQL database every 2 seconds.
# ===========================

import pymysql                 # For connecting to the MySQL database
from sense_hat import SenseHat # Library to read from Raspberry Pi Sense HAT
from datetime import datetime  # For timestamp formatting
import time                    # For sleep/delay control
import shutil                  # For locating system utilities
import subprocess              # For executing system commands (e.g., vcgencmd)

# Initialize Sense HAT
sense = SenseHat()

# Connect to MySQL database
# Make sure you have a database named 'sensor_data' and a table named 'readings'
db = pymysql.connect(
    host="localhost",
    user="root",
    password="123456",
    database="sensor_data"
)

# Create a cursor object to execute SQL commands
cursor = db.cursor()

# ---------------- CONFIGURATION PARAMETERS ----------------
# CPU_WEIGHT: used to correct the Sense HAT’s temperature (affected by CPU heat)
# OFFSET: manual calibration value based on your real environment
CPU_WEIGHT = 0.7
OFFSET = -5.5

# ---------------- FUNCTION: READ CPU TEMPERATURE ----------------
def read_cpu_temp_c():
    """
    Reads the Raspberry Pi's CPU temperature in °C.
    It first tries to use the `vcgencmd` command.
    If that fails, it reads from /sys/class/thermal/thermal_zone0/temp.
    """
    vcgencmd = shutil.which("vcgencmd")
    if vcgencmd:
        try:
            # Example output: temp=54.8'C
            out = subprocess.check_output([vcgencmd, "measure_temp"], text=True).strip()
            if "temp=" in out:
                return float(out.split("temp=")[-1].split("'")[0])
        except Exception:
            pass

    # Fallback: read temperature from system thermal zone
    try:
        with open("/sys/class/thermal/thermal_zone0/temp", "r") as f:
            return float(f.read().strip()) / 1000.0  # value is in millidegrees
    except Exception:
        return None

# ---------------- MAIN LOOP ----------------
# Continuously read sensor data and insert into MySQL
while True:
    # Read temperature, humidity, and pressure from Sense HAT
    temp_sense = round(sense.get_temperature(), 2)
    humidity = round(sense.get_humidity(), 2)
    pressure = round(sense.get_pressure(), 2)

    # Read CPU temperature
    temp_c = round(read_cpu_temp_c(), 2)

    # Compensate the temperature reading using CPU temperature
    if temp_c is not None:
        temp = temp_sense * (1 + CPU_WEIGHT) - CPU_WEIGHT * temp_c
    else:
        temp = temp_sense

    # Apply manual offset calibration
    temp += OFFSET
    temp = round(temp, 2)

    # Generate current timestamp
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    # SQL query for inserting the new record
    sql = "INSERT INTO readings (timestamp, temperature, humidity, pressure) VALUES (%s, %s, %s, %s)"
    val = (timestamp, temp, humidity, pressure)

    # Execute the query and commit changes
    cursor.execute(sql, val)
    db.commit()

    # Print debug info to the terminal
    print(f"Inserted: {timestamp}, {temp}°C, {humidity}%, {pressure} hPa")

    # Wait 2 seconds before reading again
    time.sleep(2)
