# Kubernetes 1.35 HA Cluster Setup with HAProxy and Cilium

### Architecture Overview
```bash
Node	    IP Address	        Role
-------------------------------------------------
master1	    192.168.121.154	    Control Plane
master2	    192.168.121.15	    Control Plane
master3	    192.168.121.80	    Control Plane
worker1	    192.168.121.79	    Worker
hpa	        192.168.121.129	    Load Balancer
-------------------------------------------------
```

## Part-1: Common Setup (master1,master2,master3,worker1) <br>

#### Step 1: Hostname Setup (Optional but Recommended)

