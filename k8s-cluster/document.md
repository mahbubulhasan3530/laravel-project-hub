Got it — you want same exact style + structured README, but updated for HA (3 master + HAProxy + worker).

Here is your final clean GitHub-ready README.md 👇

Kubernetes 1.35 HA Cluster Setup (Part-1: Common Setup)
Cluster Info
HAProxy (LB) : 192.168.121.129
master1      : 192.168.121.154
master2      : 192.168.121.15
master3      : 192.168.121.80
worker1      : 192.168.121.79
Step 1: Hostname Setup (Optional but Recommended)
sudo hostnamectl set-hostname <node-name>

hostnamectl
Step 2: Disable Swap
# Disable swap immediately
sudo swapoff -a

# Disable swap permanently
sudo sed -i '/ swap / s/^\(.*\)$/#\1/g' /etc/fstab

# Verify swap is disabled
free -h
Step 3: Configure Kernel Modules and System Settings
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
Verify IP Forwarding
lsmod | grep br_netfilter
sysctl net.ipv4.ip_forward
Step 4: Install Container Runtime (Containerd)
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
Step 5: Kubernetes Repository
sudo apt update
sudo apt-get install -y apt-transport-https ca-certificates curl gpg

sudo mkdir -p /etc/apt/keyrings

sudo rm -f /etc/apt/keyrings/kubernetes-apt-keyring.gpg

curl -fsSL https://pkgs.k8s.io/core:/stable:/v1.35/deb/Release.key \
| sudo gpg --dearmor -o /etc/apt/keyrings/kubernetes-apt-keyring.gpg

sudo chmod 644 /etc/apt/keyrings/kubernetes-apt-keyring.gpg

echo 'deb [signed-by=/etc/apt/keyrings/kubernetes-apt-keyring.gpg] \
https://pkgs.k8s.io/core:/stable:/v1.35/deb/ /' \
| sudo tee /etc/apt/sources.list.d/kubernetes.list

sudo apt update
Step 6: Kubernetes Components Install
sudo apt update
sudo apt install -y kubelet kubeadm kubectl

sudo apt-mark hold kubelet kubeadm kubectl

sudo systemctl enable kubelet
Kubernetes 1.35 Cluster Setup (Part-2: HAProxy Setup)
Step 7: Install HAProxy (ONLY on HAProxy Node)
sudo apt update
sudo apt install -y haproxy
Step 8: Configure HAProxy
sudo nano /etc/haproxy/haproxy.cfg

Add:

frontend kubernetes
    bind 192.168.121.129:6443
    mode tcp
    option tcplog
    default_backend k8s-masters

backend k8s-masters
    mode tcp
    balance roundrobin
    option tcp-check

    server master1 192.168.121.154:6443 check
    server master2 192.168.121.15:6443 check
    server master3 192.168.121.80:6443 check
Restart HAProxy
sudo systemctl restart haproxy
sudo systemctl enable haproxy
Install kubectl on HAProxy
sudo apt install -y kubectl
Kubernetes 1.35 Cluster Setup (Part-3: Master Initialization)
Step 9: Initialize Cluster (ONLY on master1)
sudo kubeadm init \
  --control-plane-endpoint "192.168.121.129:6443" \
  --upload-certs \
  --pod-network-cidr=10.10.0.0/16
⚠️ If Certificate Key Missing
sudo kubeadm init phase upload-certs --upload-certs
Step 10: Configure kubectl (master1)
mkdir -p $HOME/.kube
sudo cp -i /etc/kubernetes/admin.conf $HOME/.kube/config
sudo chown $(id -u):$(id -g) $HOME/.kube/config
Kubernetes 1.35 Cluster Setup (Part-4: Join Nodes)
Step 11: Join Control Plane Nodes (master2, master3)
kubeadm join 192.168.121.129:6443 \
  --token <TOKEN> \
  --discovery-token-ca-cert-hash sha256:<HASH> \
  --control-plane \
  --certificate-key <CERT_KEY>
Step 12: Join Worker Node (worker1)
kubeadm join 192.168.121.129:6443 \
  --token <TOKEN> \
  --discovery-token-ca-cert-hash sha256:<HASH>
Kubernetes 1.35 Cluster Setup (Part-5: HAProxy kubectl Access)
Step 13: Copy kubeconfig to HAProxy
On master1:
sudo cp /etc/kubernetes/admin.conf /tmp/admin.conf
sudo chmod 644 /tmp/admin.conf
On HAProxy:
mkdir -p $HOME/.kube

scp <user>@192.168.121.154:/tmp/admin.conf $HOME/.kube/config

sudo chown $(id -u):$(id -g) $HOME/.kube/config
Kubernetes 1.35 Cluster Setup (Part-6: Cilium CNI)
Step 14: Install Cilium (RUN from HAProxy or master1)
CILIUM_CLI_VERSION=$(curl -s https://raw.githubusercontent.com/cilium/cilium-cli/main/stable.txt)

CLI_ARCH=amd64
[ "$(uname -m)" = "aarch64" ] && CLI_ARCH=arm64

curl -L --fail --remote-name-all \
https://github.com/cilium/cilium-cli/releases/download/${CILIUM_CLI_VERSION}/cilium-linux-${CLI_ARCH}.tar.gz{,.sha256sum}

sha256sum --check cilium-linux-${CLI_ARCH}.tar.gz.sha256sum

sudo tar -xzvf cilium-linux-${CLI_ARCH}.tar.gz -C /usr/local/bin

rm cilium-linux-${CLI_ARCH}.tar.gz*
Install Cilium
cilium install --version v1.18.1
cilium status
Verification
kubectl get nodes

kubectl get pods -A
Test Deployment
kubectl create deployment test-nginx --image=nginx

kubectl expose deployment test-nginx \
  --port=80 --target-port=80 --type=NodePort

kubectl get pods
Cleanup
kubectl delete deployment test-nginx
kubectl delete svc test-nginx