# Amzn test

### How to run
```bash
# Build the local container image and start the service and the cache containers. 
make run
```
Once the project has been launched, a listening service will be available on `localhost:8080` which will respond with information in JSON format of the tracking code.
The service keeps the retrieved data, if valid, in cache for 60 seconds.

#### An example:
```bash
make run
# --> Wait until the container is ready

# Invalid request
curl -q --no-progress-meter http://localhost:8080/914D70016AAAA | jq
{
  "type": "error",
  "code": "404",
  "message": "INVALID TRACKING_ID"
}

# Valid request
curl -q --no-progress-meter http://localhost:8080/914D70016AAAA | jq
{
  "trackingID": "914D70016AAAA",
  "summary": {
    "shipperName": "UNKNOWN",
    "carrier": "Amazon",
    "expectedDeliveryDate": "Feb 21, 2023 8:00:00 AM"
  },
  "history": [
    {
      "code": "CreationConfirmed",
      "status": "Etichetta creata",
      "location": [],
      "time": "Jan 30, 2023 6:10:34 AM"
    },
    ...
  ]
}
```

---

### Stop the project
```bash
# Stop and destroy containers.
make stop
```