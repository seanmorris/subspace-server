echo "Building seanmorr.is\n";

docker build . -t worker.subspace.seanmorr.is -f ./worker.Dockerfile \
&& docker-compose build