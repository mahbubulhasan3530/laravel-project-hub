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
```bash
sudo hostnamectl set-hostname master1    # or master2, master3, worker1, hpa

# Verify
hostnamectl
```
#### step 2: Disable Swap
```bash
# Disable swap immediately
sudo swapoff -a

# Disable swap permanently
sudo sed -i '/ swap / s/^\(.*\)$/#\1/g' /etc/fstab

# Verify swap is disabled
free -h
```
#### step 3: Configure Kernel Modules and System Settings
```bash
# Make kernel modules persistent
cat <<EOF | sudo tee /etc/modules-load.d/k8s.conf
overlay
br_netfilter
EOF

# Load required kernel modules
sudo modprobe overlay
sudo modprobe br_netfilter

# Configure sysctl parameters
cat <<EOF | sudo tee /etc/sysctl.d/k8s.conf
net.bridge.bridge-nf-call-iptables  = 1
net.bridge.bridge-nf-call-ip6tables = 1
net.ipv4.ip_forward                 = 1
EOF

# Apply sysctl settings
sudo sysctl --system
sudo sysctl -w net.ipv4.ip_forward=1
```
### step 4: Verify Kernel Modules and IP Forwarding
```bash
lsmod | grep br_netfilter
sysctl net.ipv4.ip_forward

# If the value is 1 for net.ipv4.ip_forward then ok
```
#### step 5:  Install Container Runtime (Containerd)
```bash
# Update system packages
sudo apt update && sudo apt upgrade -y

# Install containerd
sudo apt install -y containerd

# Create containerd configuration
sudo mkdir -p /etc/containerd
containerd config default \
| sed 's/SystemdCgroup = false/SystemdCgroup = true/' \
| sed 's|sandbox_image = ".*"|sandbox_image = "registry.k8s.io/pause:3.10"|' \
| sudo tee /etc/containerd/config.toml > /dev/null

# Restart and enable containerd
sudo systemctl restart containerd
sudo systemctl enable containerd
```
#### step 6: Kubernetes Repository
```bash
# Install necessary packages
sudo apt update
sudo apt-get install -y apt-transport-https ca-certificates curl gpg

# Create GPG keyrings directory
sudo mkdir -p /etc/apt/keyrings

# Add Kubernetes GPG key and repository for v1.35
sudo rm -f /etc/apt/keyrings/kubernetes-apt-keyring.gpg
curl -fsSL https://pkgs.k8s.io/core:/stable:/v1.35/deb/Release.key | sudo gpg --dearmor -o /etc/apt/keyrings/kubernetes-apt-keyring.gpg
sudo chmod 644 /etc/apt/keyrings/kubernetes-apt-keyring.gpg

echo 'deb [signed-by=/etc/apt/keyrings/kubernetes-apt-keyring.gpg] https://pkgs.k8s.io/core:/stable:/v1.35/deb/ /' | sudo tee /etc/apt/sources.list.d/kubernetes.list

# Update package list to recognize the new version
sudo apt update

# Verify kubernetes repo
cat /etc/apt/sources.list.d/kubernetes.list
```

#### step 7: Install kubeadm, kubelet, and kubectl
```bash
# Update package index and install Kubernetes components
sudo apt update
sudo apt install -y kubelet kubeadm kubectl

# Prevent automatic updates
sudo apt-mark hold kubelet kubeadm kubectl

# Enable kubelet
sudo systemctl enable kubelet
```

## Part-2 : Master Node Setup - Initialize Cluster <br>

#### part 8: Initialize the Cluster (on master1 Only)
```bash
# Find your master node IP address
ip addr show

# Initialize the cluster with HAProxy endpoint
sudo kubeadm init \
  --control-plane-endpoint "192.168.121.129:6443" \
  --upload-certs \
  --pod-network-cidr=10.10.0.0/16
```
**<span style="color:red">CRITICAL</span>: Save the kubeadm join command from the output! You'll need it for master2, master3, and worker1.** 

**If Certificate Key Doesn't Appear (Manual Fix)** <br>
If the --certificate-key doesn't appear in the output, generate it manually. 
```bash
sudo kubeadm init phase upload-certs --upload-certs
``` 

**Expected Output Example**
```bash
kubeadm join <HAproxy-ip>:6443 \
  --token ******#####@@@@&&&&* \
  --discovery-token-ca-cert-hash sha256:-----####*********@@@@@@@ \
  --control-plane \
  --certificate-key *****************@@@@@@@@@@@################
  ```

  #### step 9 : Configure kubectl Access (on master1)
```bash
# Setup kubectl configuration
mkdir -p $HOME/.kube
sudo cp -i /etc/kubernetes/admin.conf $HOME/.kube/config
sudo chown $(id -u):$(id -g) $HOME/.kube/config

# Test access
kubectl get nodes
```

#### Step 10: Join Additional Control Plane Nodes (master2 & master3) 

* On master2 and master3, run the join command with --control-plane flag *